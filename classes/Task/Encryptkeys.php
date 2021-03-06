<?php

/**
 * Generates config file for the kohana-encrypt module
 * If option `module` is passed, then places it in MODPATH/`module`/config/encryption.php (This can be useful
 * when developing multi-page applications)
 * Otherwise uses standard APPPATH/config/encryption.php
 * @example php index.php --task=createencryptkey
 * @author Piotr Gołasz <pgolasz@gmail.com>
 */
class Task_Encryptkeys extends Minion_Task
{

	protected $_options = array(
		'module' => NULL
	);

	/**
	 * Builds module Validation
	 * @param \Validation $validation
	 * @return type
	 */
	public function build_validation(\Validation $validation)
	{
		return parent::build_validation($validation)
						->rule('module', 'array_key_exists', array(':value', Kohana::modules()));
	}

	/**
	 * @inheritdoc
	 */
	protected function _execute(array $params)
	{
		try
		{
			if (!class_exists('phpseclib\Crypt\RSA'))
			{
				throw new Kohana_Exception('phpseclib/phpseclib is required');
			}

			Minion_CLI::write('Creating RSA and AES encryption/decryption keys');

			Minion_CLI::write('Creating RSA encryption/decryption keys');
			$length = (int) Minion_CLI::read('Type length in bits of RSA key:', array(2048, 4096));

			Minion_CLI::wait(2, TRUE);

			$rsa_secretkey = $this->RandomString(32);

			$rsa = new \phpseclib\Crypt\RSA();
			$rsa->setPassword($rsa_secretkey);

			extract($rsa->createKey($length));

			$rsa_private = new \phpseclib\Crypt\RSA();
			$rsa_private->setPassword($rsa_secretkey);
			$rsa_private->loadKey($privatekey);
			$privatekey = $rsa_private->getPrivateKey(\phpseclib\Crypt\RSA::PRIVATE_FORMAT_PKCS1);

			$rsa_public = new \phpseclib\Crypt\RSA();
			$rsa_public->loadKey($publickey);
			$publickey = $rsa_public->getPublicKey(\phpseclib\Crypt\RSA::PUBLIC_FORMAT_PKCS1);

			Minion_CLI::write('RSA private key password:');
			Minion_CLI::write($rsa_secretkey);
			Minion_CLI::write('RSA publickey:');
			Minion_CLI::write($publickey);
			Minion_CLI::write('RSA private key:');
			Minion_CLI::write($privatekey);

			Minion_CLI::write('Creating certificate:');

			Minion_CLI::wait(2, TRUE);

			$subject = new \phpseclib\File\X509();
			$dn_prop_idatorganization = Minion_CLI::read('Type your organization name ...');
			$subject->setDNProp('id-at-organizationName', $dn_prop_idatorganization);
			$subject->setDNProp('name', $dn_prop_idatorganization);

			$dn_prop_email = Minion_CLI::read('Type your e-mail ...');
			$subject->setDNProp('emailaddress', $dn_prop_email);

			$dn_prop_postcode = Minion_CLI::read('Type your postcode ...');
			$subject->setDNProp('postalcode', $dn_prop_postcode);

			$dn_prop_state = Minion_CLI::read('Type your state/province ...');
			$subject->setDNProp('state', $dn_prop_state);

			$dn_prop_address = Minion_CLI::read('Type your address ...');
			$subject->setDNProp('streetaddress', $dn_prop_address);
			$subject->setPublicKey($rsa_public);

			$subject->setDNProp('id-at-serialNumber', hash('sha512', $dn_prop_idatorganization . Text::random(NULL, 24)));

			$issuer = new \phpseclib\File\X509();
			$issuer->setPrivateKey($rsa_private);
			$issuer->setDN($subject->getDN());

			$x509 = new \phpseclib\File\X509();
			$x509->setStartDate(date('Y-m-d H:i:s'));
			$x509->setEndDate(date('Y-m-d H:i:s', strtotime('+1 year')));
			$result = $x509->sign($issuer, $subject, 'sha512WithRSAEncryption');

			$rsa_certificate = $x509->saveX509($result);

			Minion_CLI::write('RSA certificate:');
			Minion_CLI::write($rsa_certificate);

			Minion_CLI::write('Creating AES key.');

			Minion_CLI::wait(2, TRUE);

			$aes_secretkey = $this->RandomString(32);

			$aes_signingkey = $this->RandomString(32);

			Minion_CLI::write('AES keys created.');
			Minion_CLI::write('AES secret key: ' . $aes_secretkey);
			Minion_CLI::write('AES signing key: ' . $aes_signingkey);

			$view = View::factory('createencryptkey')
					->bind('aes_secretkey', $aes_secretkey)
					->bind('aes_signingkey', $aes_signingkey)
					->bind('rsa_secretkey', $rsa_secretkey)
					->bind('rsa_publickey', $publickey)
					->bind('rsa_privatekey', $privatekey)
					->bind('rsa_certificate', $rsa_certificate)
					->render();

			if (!empty($params['module']))
			{
				// Maybe this is multi-application, so you want to put it into module/module_name/config/
				$put_contents = file_put_contents(MODPATH . $params['module'] . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'encrypt.php', $view);

				if ($put_contents !== FALSE)
				{
					Minion_CLI::write('Saved both keys to: ' . MODPATH . $params['module'] . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'encrypt.php');
				}
				else
				{
					Minion_CLI::write('Could not save keys to ' . MODPATH . $params['module'] . DIRECTORY_SEPARATOR . 'config, most likely permissions issue.');
				}
			}
			else
			{
				// Save to application/
				$put_contents = file_put_contents(APPPATH . 'config' . DIRECTORY_SEPARATOR . 'encrypt.php', $view);

				if ($put_contents !== FALSE)
				{
					Minion_CLI::write('Saved both keys to: ' . APPPATH . 'config' . DIRECTORY_SEPARATOR . 'encrypt.php');
				}
				else
				{
					Minion_CLI::write('Could not save keys to APPPATH/config, most likely permissions issue.');
				}
			}
		}
		catch (Exception $ex)
		{
			if (get_class($ex) == 'ErrorException' AND $ex->getCode() == 2)
			{
				Minion_CLI::write('Class PHPSECLIB not found. You have to composer install in encrypt module.');
			}
			else
			{
				Minion_CLI::write('General error occured: ' . $ex->getMessage());
			}
		}
	}

	/**
	 * Generates random non-cryptographicly-secure strings for key passwords
	 * @author Piotr Gołasz <pgolasz@gmail.com>
	 * @param integer $length
	 * @return string
	 */
	private function RandomString($length = 10)
	{
		// https://www.owasp.org/index.php/Password_special_characters
		// Use all US-keyboard characters (without single quote that messes up with string lengths)
		return Text::random('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ !"#$%()*+,-./:;<=>?@[]^_`{|}~', $length);
	}

}
