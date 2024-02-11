<?php

namespace Tco\Checkout\Helper;

class Ipn extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_session;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $_searchCriteriaBuilder;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $_quoteRepo;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Checkout\Model\Session $session,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Quote\Model\QuoteRepository $quoteRepo
    ) {
        $this->_session = $session;
        parent::__construct($context);

        $this->_orderFactory = $orderFactory;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_quoteRepo = $quoteRepo;
    }

    public function calculateIpnResponse($ipn_params, $secret_key, $algo='sha3-256')
    {
        $result_response = '';
        $ipn_params_response = [];
        $ipn_params_response['IPN_PID'][0] = $ipn_params['IPN_PID'][0];
        $ipn_params_response['IPN_PNAME'][0] = $ipn_params['IPN_PNAME'][0];
        $ipn_params_response['IPN_DATE'] = $ipn_params['IPN_DATE'];
        $ipn_params_response['DATE'] = date('YmdHis');

        foreach ($ipn_params_response as $key => $val) {
            $result_response .= $this->arrayExpand((array)$val);
        }

        if('md5' === $algo)
            return sprintf(
                '<EPAYMENT>%s|%s</EPAYMENT>',
                $ipn_params_response['DATE'],
                $this->hmac($secret_key, $result_response, $algo)
            );
        else
            return sprintf(
                '<sig algo="%s" date="%s">%s</sig>',
                $algo,
                $ipn_params_response['DATE'],
                $this->hmac($secret_key, $result_response, $algo)
            );
    }

    public function arrayExpand($array)
    {
        $retval = "";
        for ($i = 0; $i < count($array); $i++) {
            $size = strlen(StripSlashes($array[$i]));
            $retval .= $size . StripSlashes($array[$i]);
        }
        return $retval;
    }

    public function hmac($key, $data, $algo = 'sha3-256')
    {
        if ('sha3-256' === $algo) {
            return hash_hmac($algo, $data, $key);
        }

        $b = 64; // byte length for hash
        if (strlen($key) > $b) {
            $key = pack("H*", hash($algo,$key));
        }

        $key = str_pad($key, $b, chr(0x00));
        $ipad = str_pad('', $b, chr(0x36));
        $opad = str_pad('', $b, chr(0x5c));
        $k_ipad = $key ^ $ipad;
        $k_opad = $key ^ $opad;

        return hash($algo,$k_opad . pack("H*", hash($algo,$k_ipad . $data)));
    }

    public function getOrderByIncrementId($incrementId, $refNo)
    {
        $found_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
        if ($found_order->getId() && $refNo == $found_order->getExtOrderId()) {
            return $found_order;
        }
        return false;
    }

    public function getQuoteByIncrementId($incrementId)
    {
        $sc = $this->_searchCriteriaBuilder->addFilter(\Magento\Quote\Api\Data\CartInterface::KEY_RESERVED_ORDER_ID,
            $incrementId)->create();
        $quoteArr = $this->_quoteRepo->getList($sc)->getItems();
        $quote = null;

        if (count($quoteArr) > 0) {
            $quote = current($quoteArr);
        }
        return $quote;
    }
}
