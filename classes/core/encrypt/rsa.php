<?php

defined('SYSPATH') or die('No direct script access.');

/**
 * RSA key encryption
 * @author Piotr Gołasz <piotr.golasz@etendard.pl>
 */
class Core_Encrypt_Rsa extends Core_Encrypt_Engine {

	/**
	 * Available hash algorithms
	 */
	const HASHES = array('sha224', 'sha256', 'sha384', 'sha512');

	/**
	 * Default hash algorithm
	 */
	const DEFAULT_HASH = 'sha512';

	private $_private;
	protected $_public;
	protected $_hash;

	/**
	 * Constructor
	 * @throws Kohana_Exception
	 */
	public function __construct()
	{
		$config = Kohana::$config->load('encryption.rsa');

		if (!isset($config['key']) OR mb_strlen($config['key'], '8bit') != 32)
		{
			throw new Kohana_Exception(__CLASS__ . ' key is not set or length is different then :length. Private key must be password-protected, otherwise everyone with access to the file will be able to forge signatures.', array(
		':length' => 32
			));
		}

		$this->_key = (String) $config['key'];

		if (!isset($config['public']))
		{
			throw new Kohana_Exception(__CLASS__ . ' public key is not set.');
		}
		else
		{
			$publickeyinfo = openssl_get_publickey($config['public']);
			
			if(!is_resource($publickeyinfo))
			{
				throw new Kohana_Exception('Public key is incorrectly formatted!');
			}
			
			$public_key_data = openssl_pkey_get_details($publickeyinfo);

			if (is_array($public_key_data) AND strpos($public_key_data['key'], '-----BEGIN PUBLIC KEY-----') !== FALSE)
			{
				$this->_public = $publickeyinfo;
			}
			else
			{
				throw new Kohana_Exception(__CLASS__ . ' this public key is not valid.');
			}
		}

		if (!isset($config['private']))
		{
			throw new Kohana_Exception(__CLASS__ . ' private key is not set.');
		}
		else
		{
			$privatekeyinfo = openssl_pkey_get_private($config['private'], $this->_key);
			
			if(!is_resource($privatekeyinfo))
			{
				throw new Kohana_Exception('Private key is incorrectly formatted!');
			}
			
			$private_key_data = openssl_pkey_get_details($privatekeyinfo);

			if (is_array($private_key_data) AND strpos($private_key_data['key'], '-----BEGIN PUBLIC KEY-----') !== FALSE)
			{
				$this->_private = $privatekeyinfo;
			}
			else
			{
				throw new Kohana_Exception(__CLASS__ . ' this public key is not valid.');
			}
		}

		if ($public_key_data['key'] != $private_key_data['key'])
		{
			throw new Kohana_Exception(__CLASS__ . ' public and private key don\'t match.');
		}

		if (isset($config['hash']) AND in_array($config['hash'], self::HASHES))
		{
			$this->_hash = (String) $config['hash'];
		}
		else
		{
			$this->_hash = self::DEFAULT_HASH;
		}
	}

	/**
	 * Decodes and splits the result into data, IV and MAC
	 * @param JSON $payload
	 * @return boolean
	 */
	protected function getJsonPayload($payload)
	{
		$payload = json_decode(base64_decode($payload), true);
		// If the payload is not valid JSON or does not have the proper keys set we will
		// assume it is invalid and bail out of the routine since we will not be able
		// to decrypt the given value. We'll also check the MAC for this encryption.
		if (!$payload || $this->invalidPayload($payload))
		{
			return FALSE;
		}
		if (!$this->verify($payload['value'], $payload['sgn']))
		{
			return FALSE;
		}
		return $payload;
	}

	/**
	 * Check if payload is correct
	 * @param array $data
	 * @return boolean
	 */
	protected function invalidPayload($data)
	{
		return !is_array($data) || !isset($data['value']) || !isset($data['sgn']);
	}

	/**
	 * Decrypts given data with key
	 * @param String $ciphertext
	 * @return String
	 */
	public function decode($ciphertext)
	{
		$payload = $this->getJsonPayload($ciphertext);
		if ($payload === FALSE)
		{
			return FALSE;
		}
		try
		{
			$decrypted = NULL;
			openssl_private_decrypt(base64_decode($payload['value']), $decrypted, $this->_private, OPENSSL_PKCS1_OAEP_PADDING);
			return $decrypted;
		}
		catch (Exception $ex)
		{
			return FALSE;
		}
	}

	/**
	 * Encrypts data with given key
	 * @param String $message
	 * @return String
	 */
	public function encode($message)
	{
		try
		{
			$crypted = NULL;
			openssl_public_encrypt($message, $crypted, $this->_public, OPENSSL_PKCS1_OAEP_PADDING);

			$crypted = base64_encode($crypted);

			return base64_encode(json_encode(array(
				'value' => $crypted,
				'sgn' => $this->sign($crypted)
			)));
		}
		catch (Exception $ex)
		{
			return FALSE;
		}
	}

	/**
	 * Sign message with given private key
	 * @param String $message
	 * @return String
	 */
	public function sign($message)
	{
		try
		{
			openssl_sign($message, $signature, $this->_private, $this->_hash);
			return base64_encode($signature);
		}
		catch (Exception $ex)
		{
			return FALSE;
		}
	}

	/**
	 * Verify private-key signed signature with public key
	 * @param String $message
	 * @param String $signature
	 * @return boolean
	 */
	public function verify($message, $signature)
	{
		try
		{
			return openssl_verify($message, base64_decode($signature), $this->_public, $this->_hash);
		}
		catch (Exception $ex)
		{
			return FALSE;
		}
	}

	/**
	 * IV automatically generated by openssl in RSA
	 */
	protected function getIvSize()
	{
		
	}

}
