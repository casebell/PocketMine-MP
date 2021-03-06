<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe\auth;

use Mdanter\Ecc\Crypto\Key\PublicKeyInterface;
use Mdanter\Ecc\Crypto\Signature\Signature;
use Mdanter\Ecc\Serializer\PublicKey\DerPublicKeySerializer;
use Mdanter\Ecc\Serializer\PublicKey\PemPublicKeySerializer;
use Mdanter\Ecc\Serializer\Signature\DerSignatureSerializer;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\scheduler\AsyncTask;
use function assert;
use function base64_decode;
use function bin2hex;
use function explode;
use function gmp_init;
use function json_decode;
use function openssl_verify;
use function str_repeat;
use function str_split;
use function strlen;
use function strtr;
use function time;
use const OPENSSL_ALGO_SHA384;

class ProcessLoginTask extends AsyncTask{
	private const TLS_KEY_SESSION = "session";

	public const MOJANG_ROOT_PUBLIC_KEY = "MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAE8ELkixyLcwlZryUQcu1TvPOmI2B7vX83ndnWRUaXm74wFfa5f/lwQNTfrLVHa2PmenpGI6JhIMUJaWZrjmMj90NoKNFSNBuKdm8rYiXsfaz3K36x/1U26HpG0ZxK/V1V";

	private const CLOCK_DRIFT_MAX = 60;

	/** @var LoginPacket */
	private $packet;

	/**
	 * @var string|null
	 * Whether the keychain signatures were validated correctly. This will be set to an error message if any link in the
	 * keychain is invalid for whatever reason (bad signature, not in nbf-exp window, etc). If this is non-null, the
	 * keychain might have been tampered with. The player will always be disconnected if this is non-null.
	 */
	private $error = "Unknown";
	/**
	 * @var bool
	 * Whether the player is logged into Xbox Live. This is true if any link in the keychain is signed with the Mojang
	 * root public key.
	 */
	private $authenticated = false;
	/** @var bool */
	private $authRequired;

	/** @var PublicKeyInterface|null */
	private $clientPublicKey = null;

	public function __construct(NetworkSession $session, LoginPacket $packet, bool $authRequired){
		$this->storeLocal(self::TLS_KEY_SESSION, $session);
		$this->packet = $packet;
		$this->authRequired = $authRequired;
	}

	public function onRun() : void{
		try{
			$this->clientPublicKey = $this->validateChain();
			$this->error = null;
		}catch(VerifyLoginException $e){
			$this->error = $e->getMessage();
		}
	}

	private function validateChain() : PublicKeyInterface{
		$packet = $this->packet;

		$currentKey = null;
		$first = true;

		foreach($packet->chainDataJwt as $jwt){
			$this->validateToken($jwt, $currentKey, $first);
			if($first){
				$first = false;
			}
		}

		/** @var string $clientKey */
		$clientKey = $currentKey;

		$this->validateToken($packet->clientDataJwt, $currentKey);

		return (new DerPublicKeySerializer())->parse(base64_decode($clientKey, true));
	}

	/**
	 * @throws VerifyLoginException if errors are encountered
	 */
	private function validateToken(string $jwt, ?string &$currentPublicKey, bool $first = false) : void{
		[$headB64, $payloadB64, $sigB64] = explode('.', $jwt);

		if($currentPublicKey === null){
			if(!$first){
				throw new VerifyLoginException("%pocketmine.disconnect.invalidSession.missingKey");
			}

			//First link, check that it is self-signed
			$headers = json_decode(self::b64UrlDecode($headB64), true);
			$currentPublicKey = $headers["x5u"];
		}

		$plainSignature = self::b64UrlDecode($sigB64);
		assert(strlen($plainSignature) === 96);
		[$rString, $sString] = str_split($plainSignature, 48);
		$sig = new Signature(gmp_init(bin2hex($rString), 16), gmp_init(bin2hex($sString), 16));

		$derSerializer = new DerPublicKeySerializer();
		$v = openssl_verify(
			"$headB64.$payloadB64",
			(new DerSignatureSerializer())->serialize($sig),
			(new PemPublicKeySerializer($derSerializer))->serialize($derSerializer->parse(base64_decode($currentPublicKey, true))),
			OPENSSL_ALGO_SHA384
		);

		if($v !== 1){
			throw new VerifyLoginException("%pocketmine.disconnect.invalidSession.badSignature");
		}

		if($currentPublicKey === self::MOJANG_ROOT_PUBLIC_KEY){
			$this->authenticated = true; //we're signed into xbox live
		}

		$claims = json_decode(self::b64UrlDecode($payloadB64), true);

		$time = time();
		if(isset($claims["nbf"]) and $claims["nbf"] > $time + self::CLOCK_DRIFT_MAX){
			throw new VerifyLoginException("%pocketmine.disconnect.invalidSession.tooEarly");
		}

		if(isset($claims["exp"]) and $claims["exp"] < $time - self::CLOCK_DRIFT_MAX){
			throw new VerifyLoginException("%pocketmine.disconnect.invalidSession.tooLate");
		}

		$currentPublicKey = $claims["identityPublicKey"] ?? null; //if there are further links, the next link should be signed with this
	}

	private static function b64UrlDecode(string $str) : string{
		if(($len = strlen($str) % 4) !== 0){
			$str .= str_repeat('=', 4 - $len);
		}
		return base64_decode(strtr($str, '-_', '+/'), true);
	}

	public function onCompletion() : void{
		/** @var NetworkSession $session */
		$session = $this->fetchLocal(self::TLS_KEY_SESSION);
		$session->setAuthenticationStatus($this->authenticated, $this->authRequired, $this->error, $this->clientPublicKey);
	}
}
