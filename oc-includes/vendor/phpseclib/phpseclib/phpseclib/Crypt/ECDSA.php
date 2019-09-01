<?php

/**
 * Pure-PHP implementation of ECDSA.
 *
 * PHP version 5
 *
 * Here's an example of how to create signatures and verify signatures with this library:
 * <code>
 * <?php
 * include 'vendor/autoload.php';
 *
 * $private = \phpseclib\Crypt\ECDSA::createKey('secp256k1');
 * $public = $private->getPublicKey();
 *
 * $plaintext = 'terrafrost';
 *
 * $signature = $private->sign($plaintext);
 *
 * echo $public->verify($plaintext, $signature) ? 'verified' : 'unverified';
 * ?>
 * </code>
 *
 * @category  Crypt
 * @package   ECDSA
 * @author    Jim Wigginton <terrafrost@php.net>
 * @copyright 2016 Jim Wigginton
 * @license   http://www.opensource.org/licenses/mit-license.html  MIT License
 * @link      http://phpseclib.sourceforge.net
 */

namespace phpseclib\Crypt;

use phpseclib\Crypt\Common\AsymmetricKey;
use phpseclib\Crypt\ECDSA\PrivateKey;
use phpseclib\Crypt\ECDSA\PublicKey;
use phpseclib\Crypt\ECDSA\Parameters;
use phpseclib\Crypt\ECDSA\BaseCurves\TwistedEdwards as TwistedEdwardsCurve;
use phpseclib\Crypt\ECDSA\Curves\Ed25519;
use phpseclib\Crypt\ECDSA\Curves\Ed448;
use phpseclib\Crypt\ECDSA\Formats\Keys\PKCS1;
use phpseclib\File\ASN1\Maps\ECParameters;
use phpseclib\File\ASN1;
use phpseclib\Exception\UnsupportedCurveException;
use phpseclib\Exception\UnsupportedAlgorithmException;

/**
 * Pure-PHP implementation of ECDSA.
 *
 * @package ECDSA
 * @author  Jim Wigginton <terrafrost@php.net>
 * @access  public
 */
abstract class ECDSA extends AsymmetricKey
{
    /**
     * Algorithm Name
     *
     * @var string
     * @access private
     */
    const ALGORITHM = 'ECDSA';

    /**
     * Public Key QA
     *
     * @var object[]
     */
    protected $QA;

    /**
     * Curve
     *
     * @var \phpseclib\Crypt\ECDSA\BaseCurves\Base
     */
    protected $curve;

    /**
     * Signature Format
     *
     * @var string
     * @access private
     */
    protected $format;

    /**
     * Signature Format (Short)
     *
     * @var string
     * @access private
     */
    protected $shortFormat;

    /**
     * Curve Name
     *
     * @var string
     */
    private $curveName;

    /**
     * Curve Order
     *
     * Used for deterministic ECDSA
     *
     * @var \phpseclib\Math\BigInteger
     */
    protected $q;

    /**
     * Alias for the private key
     *
     * Used for deterministic ECDSA. AsymmetricKey expects $x. I don't like x because
     * with x you have x * the base point yielding an (x, y)-coordinate that is the
     * public key. But the x is different depending on which side of the equal sign
     * you're on. It's less ambiguous if you do dA * base point = (x, y)-coordinate.
     *
     * @var \phpseclib\Math\BigInteger
     */
    protected $x;

    /**
     * Context
     *
     * @var string
     */
    protected $context;

    /**
     * Create public / private key pair.
     *
     * @access public
     * @param string $curve
     * @return \phpseclib\Crypt\ECDSA\PrivateKey
     */
    public static function createKey($curve)
    {
        self::initialize_static_variables();

        if (!isset(self::$engines['PHP'])) {
            self::useBestEngine();
        }

        $curve = strtolower($curve);
        if (self::$engines['libsodium'] && $curve == 'ed25519' && function_exists('sodium_crypto_sign_keypair')) {
            $kp = sodium_crypto_sign_keypair();

            $privatekey = ECDSA::load(sodium_crypto_sign_secretkey($kp), 'libsodium');
            //$publickey = ECDSA::load(sodium_crypto_sign_publickey($kp), 'libsodium');

            $privatekey->curveName = 'Ed25519';
            //$publickey->curveName = $curve;

            return $privatekey;
        }

        $privatekey = new PrivateKey;

        $curveName = $curve;
        $curve = '\phpseclib\Crypt\ECDSA\Curves\\' . $curve;
        if (!class_exists($curve)) {
            throw new UnsupportedCurveException('Named Curve of ' . $curveName . ' is not supported');
        }

        $reflect = new \ReflectionClass($curve);
        $curveName = $reflect->isFinal() ?
            $reflect->getParentClass()->getShortName() :
            $reflect->getShortName();

        $curve = new $curve();
        $privatekey->dA = $dA = $curve->createRandomMultiplier();
        $privatekey->QA = $curve->multiplyPoint($curve->getBasePoint(), $dA);
        $privatekey->curve = $curve;

        //$publickey = clone $privatekey;
        //unset($publickey->dA);
        //unset($publickey->x);

        $privatekey->curveName = $curveName;
        //$publickey->curveName = $curveName;

        if ($privatekey->curve instanceof TwistedEdwardsCurve) {
            return $privatekey->withHash($curve::HASH);
        }

        return $privatekey;
    }

    /**
     * Loads a public or private key
     *
     * Returns true on success and false on failure (ie. an incorrect password was provided or the key was malformed)
     * @return bool
     * @access public
     * @param string $key
     * @param string $type optional
     * @param string $password optional
     */
    public static function load($key, $type = false, $password = false)
    {
        self::initialize_static_variables();

        if (!isset(self::$engines['PHP'])) {
            self::useBestEngine();
        }

        $components = parent::load($key, $type, $password);

        if (!isset($components['dA']) && !isset($components['QA'])) {
            $new = new Parameters;
            $new->curve = $components['curve'];
            return $new;
        }

        $new = isset($components['dA']) ?
            new PrivateKey :
            new PublicKey;
        $new->curve = $components['curve'];
        $new->QA = $components['QA'];

        if (isset($components['dA'])) {
            $new->dA = $components['dA'];
        }

        if ($new->curve instanceof TwistedEdwardsCurve) {
            return $new->withHash($components['curve']::HASH);
        }

        return $new;
    }

    /**
     * Constructor
     *
     * PublicKey and PrivateKey objects can only be created from abstract RSA class
     */
    protected function __construct()
    {
        $this->format = self::validatePlugin('Signature', 'ASN1');
        $this->shortFormat = 'ASN1';

        parent::__construct();
    }

    /**
     * Returns the curve
     *
     * Returns a string if it's a named curve, an array if not
     *
     * @access public
     * @return string|array
     */
    public function getCurve()
    {
        if ($this->curveName) {
            return $this->curveName;
        }

        if ($this->curve instanceof TwistedEdwardsCurve) {
            $this->curveName = $this->curve instanceof Ed25519 ? 'Ed25519' : 'Ed448';
            return $this->curveName;
        }

        $params = $this->getParameters()->toString('PKCS8', ['namedCurve' => true]);
        $decoded = ASN1::extractBER($params);
        $decoded = ASN1::decodeBER($decoded);
        $decoded = ASN1::asn1map($decoded[0], ECParameters::MAP);
        if (isset($decoded['namedCurve'])) {
            $this->curveName = $decoded['namedCurve'];
            return $decoded['namedCurve'];
        }

        if (!$namedCurves) {
            PKCS1::useSpecifiedCurve();
        }

        return $decoded;
    }

    /**
     * Returns the key size
     *
     * Quoting https://tools.ietf.org/html/rfc5656#section-2,
     *
     * "The size of a set of elliptic curve domain parameters on a prime
     *  curve is defined as the number of bits in the binary representation
     *  of the field order, commonly denoted by p.  Size on a
     *  characteristic-2 curve is defined as the number of bits in the binary
     *  representation of the field, commonly denoted by m.  A set of
     *  elliptic curve domain parameters defines a group of order n generated
     *  by a base point P"
     *
     * @access public
     * @return int
     */
    public function getLength()
    {
        return $this->curve->getLength();
    }

    /**
     * Returns the current engine being used
     *
     * @see self::useInternalEngine()
     * @see self::useBestEngine()
     * @access public
     * @return string
     */
    public function getEngine()
    {
        if ($this->curve instanceof TwistedEdwardsCurve) {
            return $this->curve instanceof Ed25519 && self::$engines['libsodium'] && !isset($this->context) ?
                'libsodium' : 'PHP';
        }

        return self::$engines['OpenSSL'] && in_array($this->hash->getHash(), openssl_get_md_methods()) ?
            'OpenSSL' : 'PHP';
    }

    /**
     * Returns the parameters
     *
     * @see self::getPublicKey()
     * @access public
     * @param string $type optional
     * @return mixed
     */
    public function getParameters($type = 'PKCS1')
    {
        $type = self::validatePlugin('Keys', $type, 'saveParameters');

        $key = $type::saveParameters($this->curve);

        return ECDSA::load($key, 'PKCS1')
            ->withHash($this->hash->getHash())
            ->withSignatureFormat($this->shortFormat);
    }

    /**
     * Determines the signature padding mode
     *
     * Valid values are: ASN1, SSH2, Raw
     *
     * @access public
     * @param string $padding
     */
    public function withSignatureFormat($format)
    {
        $new = clone $this;
        $new->shortFormat = $format;
        $new->format = self::validatePlugin('Signature', $format);
        return $new;
    }

    /**
     * Returns the signature format currently being used
     *
     * @access public
     */
    public function getSignatureFormat()
    {
       return $this->shortFormat;
    }

    /**
     * Sets the context
     *
     * Used by Ed25519 / Ed448.
     *
     * @see self::sign()
     * @see self::verify()
     * @access public
     * @param string $context optional
     */
    public function withContext($context = null)
    {
        if (!$this->curve instanceof TwistedEdwardsCurve) {
            throw new UnsupportedCurveException('Only Ed25519 and Ed448 support contexts');
        }

        $new = clone $this;
        if (!isset($context)) {
            $new->context = null;
            return $new;
        }
        if (!is_string($context)) {
            throw new \InvalidArgumentException('setContext expects a string');
        }
        if (strlen($context) > 255) {
            throw new \LengthException('The context is supposed to be, at most, 255 bytes long');
        }
        $new->context = $context;
        return $new;
    }

    /**
     * Returns the signature format currently being used
     *
     * @access public
     */
    public function getContext()
    {
       return $this->context;
    }

    /**
     * Determines which hashing function should be used
     *
     * @access public
     * @param string $hash
     */
    public function withHash($hash)
    {
        if ($this->curve instanceof Ed25519 && $hash != 'sha512') {
            throw new UnsupportedAlgorithmException('Ed25519 only supports sha512 as a hash');
        }
        if ($this->curve instanceof Ed448 && $hash != 'shake256-912') {
            throw new UnsupportedAlgorithmException('Ed448 only supports shake256 with a length of 114 bytes');
        }

        return parent::withHash($hash);
    }
}