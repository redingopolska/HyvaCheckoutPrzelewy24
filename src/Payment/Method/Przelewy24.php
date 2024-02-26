<?php

namespace Hyva\RedingoPrzelewy24\Payment\Method;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as SessionCheckout;
use Magento\Framework\App\Cache;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use PayPro\Przelewy24\Model\Ui\ConfigProvider;

class Przelewy24 extends Component implements EvaluationInterface
{
    private const CACHE_KEY = 'przelewy24_channels';
    public int $method = 0;

    //public int $group = 0;
    public string $blikCode = '';
    public string $methodName = '';
    public bool $regulationAccept = true;

    //public array $methods = [];

    public function __construct(
        private readonly ConfigProvider          $p24ConfigProvider,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly SessionCheckout         $sessionCheckout,
        private readonly Cache                   $cache
    ) {
    }

    public function mount(): void
    {
        $data = $this->sessionCheckout->getQuote()->getPayment()->getAdditionalInformation();
        // $this->group = (int) $data['group'] ?? 0;
        $this->method = (int) $data['method'] ?? '';
        $this->methodName = $data['method_name'] ?? '';
        //$this->blikCode = $data['blik_code'] ?? '';
        $this->regulationAccept = $data['regulation_accept'] ?? false;
    }

    public function updated($value, $name)
    {
       
        $quote = $this->sessionCheckout->getQuote();
        // $quote->getPayment()->setAdditionalInformation('group', $this->group);
        $quote->getPayment()->setAdditionalInformation('method', $this->method);
        // $quote->getPayment()->setAdditionalInformation('blik_code', $this->blikCode);
        $quote->getPayment()->setAdditionalInformation('regulation_accept', $this->regulationAccept);
        $this->quoteRepository->save($quote);

        return $value;
    }

    public function getTerms()
    {
        $config = $this->p24ConfigProvider->getConfig();
        return  $config['payment']['przelewy24']['regulations'];
    }
    public function getLogo()
    {
        $config = $this->p24ConfigProvider->getConfig();
        return  $config['payment']['przelewy24']['logoUrl'];
    }
    
    public function getMethodName()
    {
        $channelsData = $this->getChannels();
       
        $ch = array_column($channelsData, 'name', 'id');
        $this->methodName = isset($ch[$this->method]) ? $ch[$this->method] : '';
        
        return $this->methodName;
    }

    public function getChannels()
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            $cached = json_decode($cached, true);
            if ($cached) {
                return $cached;
            }
        }

        $config = $this->p24ConfigProvider->getConfig();

        $url =  $config['payment']['przelewy24']['paymentMethodsUrl'];
        $data = file_get_contents($url);
        
        $this->cache->save($data, self::CACHE_KEY);

        return json_decode($data, true);
    }

    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        if ($this->sessionCheckout->getQuote()->getPayment()->getMethod() != 'przelewy24') {
            return $resultFactory->createSuccess();
        }

        // if (!empty($this->blikCode) && strlen($this->blikCode) != 6) {
        //     return $resultFactory->createBlocking(__('Invalid BLIK code'));
        // }

        if (empty($this->blikCode) && $this->method < 1) {
            return $resultFactory->createBlocking(__('Payment method not selected'));
        }

        if (!$this->regulationAccept) {
            return $resultFactory->createBlocking(__('Regulations not accepted'));
        }
        
        $quote = $this->sessionCheckout->getQuote();
        $additionalInfo = $quote->getPayment()->getAdditionalInformation();
        $quote->getPayment()->setAdditionalInformation('method', $this->method);
        $quote->getPayment()->setAdditionalInformation('regulation_accept', $this->regulationAccept);
        $this->quoteRepository->save($quote);



        //return $resultFactory->createBlocking(__('Payment blocked for testing purposes'));
        return $resultFactory->createSuccess();
    }
}
