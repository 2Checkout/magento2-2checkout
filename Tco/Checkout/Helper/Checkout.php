<?php

namespace Tco\Checkout\Helper;

class Checkout extends \Magento\Framework\App\Helper\AbstractHelper
{

    protected $_session;

    private $_sign_params = [
      'return-url',
      'return-type',
      'expiration',
      'order-ext-ref',
      'item-ext-ref',
      'lock',
      'cust-params',
      'customer-ref',
      'customer-ext-ref',
      'currency',
      'prod',
      'price',
      'qty',
      'tangible',
      'type',
      'opt',
      'coupon',
      'description',
      'recurrence',
      'duration',
      'renewal-price',
    ];

    public function __construct(
      \Magento\Framework\App\Helper\Context $context,
      \Magento\Checkout\Model\Session $session
    ) {
        $this->_session = $session;
        parent::__construct($context);
    }

    public function cancelCurrentOrder($comment)
    {
        $order = $this->_session->getLastRealOrder();
        if ($order->getId() && $order->getState() != \Magento\Sales\Model\Order::STATE_CANCELED) {
            $order->registerCancellation($comment)->save();
            return true;
        }
        return false;
    }

    public function restoreQuote()
    {
        return $this->_session->restoreQuote();
    }

    public function getUrl($route, $params = [])
    {
        return $this->_getUrl($route, $params);
    }

    //*********FUNCTIONS FOR ConvertPlus Signature*********

    /**
     * generates ConvertPlus signature
     *
     * @param array $params
     * @param string $secret_word
     * @param bool $from_response
     *
     * @return string
     */
    public function generateSignature(
      $params,
      $secret_word,
      $from_response = false
    ) {

        if (!$from_response) {
            $_sign_params = array_filter($params, function ($k) {
                return in_array($k, $this->_sign_params);
            }, ARRAY_FILTER_USE_KEY);
        } else {
            $_sign_params = $params;
            if (isset($_sign_params['signature'])) {
                unset($_sign_params['signature']);
            }
        }

        ksort($_sign_params); // order by key
        // Generate Hash
        $string = "";
        foreach ($_sign_params as $key => $value) {
            $value = is_array($value) ? $value[0] : $value;
            $string .= strlen($value) . $value;
        }

        return bin2hex(hash_hmac('sha256', $string, $secret_word, true));
    }

    /**
     * @param $sub
     * @param $iat
     * @param $exp
     * @param $buyLinkSecretWord
     *
     * @return string
     */
    public function generateJWTToken($sub, $iat, $exp, $buyLinkSecretWord)
    {
        $header = $this->encode(json_encode(['alg' => 'HS512', 'typ' => 'JWT']));
        $payload = $this->encode(json_encode(['sub' => $sub, 'iat' => $iat, 'exp' => $exp]));
        $signature = $this->encode(
          hash_hmac('sha512', "$header.$payload", $buyLinkSecretWord, true)
        );

        return implode('.', [
          $header,
          $payload,
          $signature
        ]);
    }

    /**
     * @param $data
     *
     * @return string|string[]
     */
    private function encode($data)
    {
        return str_replace('=', '', strtr(base64_encode($data), '+/', '-_'));
    }

    /**
     * @param $payload
     *
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getInlineSignature(
      $merchantId,
      $buyLinkSecretWord,
      $payload
    ) {
        $jwtToken = $this->generateJWTToken(
          $merchantId,
          time(),
          time() + 3600,
          $buyLinkSecretWord
        );
        $curl = curl_init();
        curl_setopt_array($curl, [
          CURLOPT_URL => "https://secure.2checkout.com/checkout/api/encrypt/generate/signature",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS => json_encode($payload),
          CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'cache-control: no-cache',
            'merchant-token: ' . $jwtToken,
          ],
        ]);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        if ($err) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Unable to get signature for payload'));
        }

        $response = json_decode($response, true);
        if (JSON_ERROR_NONE !== json_last_error() || !isset($response['signature'])) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Received response is not recognized'));
        }

        return $response['signature'];
    }

    /**
     * filter empty/null array entries.
     *
     * @param array $arr
     *
     * @return array
     */
    public function removeArrEmptyValues($arr)
    {
        return array_filter($arr, function ($val) {
            return '' !== trim($val);
        });
    }

    /**
     * filter empty/null array entries.
     *
     * @param array $arr
     *
     * @return array
     */
    public function trimArray($arr)
    {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $arr[$key] = $this->trimArray($value);
            } else {
                $arr[$key] = trim($value);
            }
        }
        return $arr;
    }

    //*********FUNCTIONS FOR HMAC*********

    /**
     * generates hmac
     *
     * @param string $key
     * @param string $data
     *
     * @return string
     */
    public function generateHash($key, $data, $algo='sha3-256')
    {
        if ('sha3-256' === $algo) {
            return hash_hmac($algo, $data, $key);
        }

        $b = 64; // byte length for hash
        if (strlen($key) > $b) {
            $key = pack("H*", hash($algo, $key));
        }

        $key = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;

        return hash($algo, $k_opad . pack("H*", hash($algo, $k_ipad . $data)));
    }

    public function arrayExpand($array)
    {
        $retval = "";
        for ($i = 0; $i < count($array); $i++) {
            $size = strlen(stripslashes($array[$i]));
            $retval .= $size . stripslashes($array[$i]);
        }
        return $retval;
    }

}
