<?php

namespace Ruudk\Payment\StripeBundle\Plugin;

use JMS\Payment\CoreBundle\Entity\ExtendedData;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\ActionRequiredException;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Stripe\PaymentIntentsGateway;
use Psr\Log\LoggerInterface;

class CheckoutPaymentIntentsPlugin extends AbstractPlugin
{
    /**
     * @var \Omnipay\Stripe\PaymentIntentsGateway
     */
    protected $gateway;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $processesType;

    /**
     * CheckoutPaymentIntentsPlugin constructor.
     *
     * @param PaymentIntentsGateway $gateway
     */
    public function __construct(PaymentIntentsGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * @param $processesType
     */
    public function setProcessesType($processesType)
    {
        $this->processesType = $processesType;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function processes($name)
    {
        return $name == $this->processesType;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param                               $retry
     *
     * @throws ActionRequiredException
     * @throws FinancialException
     */
    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        if ($transaction->getState() === FinancialTransactionInterface::STATE_PENDING) {
            $parameters = $this->getConfirmParameters($transaction);
            $response = $this->gateway->confirm($parameters)->send();
        } else {
            $parameters = $this->getPurchaseParameters($transaction);
            $response = $this->gateway->purchase($parameters)->send();
        }

        if ($this->logger) {
            $this->logger->info(json_encode($response->getRequest()->getData()));
            $this->logger->info(json_encode($response->getData()));
        }

        $data = $response->getData();

        if (array_key_exists('id', $data)) {
            $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('payment_intent_id', $response->getPaymentIntentReference());
        }

        if (isset($data['charges']['data']) && is_array($data['charges']['data'])) {
            $charge = array_shift($data['charges']['data']);

            if ($charge) {
                if (array_key_exists('id', $charge)) {
                    $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('charge_id', $charge['id']);
                }

                if (array_key_exists('balance_transaction', $charge)) {
                    $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('balance_transaction_id', $charge['balance_transaction']);
                }

                if (array_key_exists('application_fee', $charge)) {
                    $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('application_fee_id', $charge['application_fee']);
                }
            }
        }

        if ($response->isSuccessful()) {
            $transaction->setReferenceNumber($response->getPaymentIntentReference());

            $transaction->setProcessedAmount($data['amount'] / 100);
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);

            if ($this->logger) {
                $this->logger->info(sprintf(
                    'Payment is successful for transaction "%s".',
                    $response->getPaymentIntentReference()
                ));
            }

            return;
        } else if ($response->isStripeSDKAction()) {
            $transaction->setReferenceNumber($response->getPaymentIntentReference());
            $transaction->getExtendedData()->set('client_secret', $response->getClientSecret());

            throw new ActionRequiredException();
        }

        if ($this->logger) {
            $this->logger->info(sprintf(
                'Payment failed for transaction "%s" with message: %s.',
                $response->getPaymentIntentReference(),
                $response->getMessage()
            ));
        }

        $ex = $this->handleError($response, $transaction);

        throw $ex;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @param                               $retry
     *
     * @throws FinancialException
     */
    public function credit(FinancialTransactionInterface $transaction, $retry)
    {
        $parameters = $this->getCreditParameters($transaction);

        $response = $this->gateway->refund($parameters)->send();

        if ($this->logger) {
            $this->logger->info(json_encode($response->getRequest()->getData()));
            $this->logger->info(json_encode($response->getData()));
        }

        if ($response->isSuccessful()) {
            $data = $response->getData();

            $extendedData = new ExtendedData();
            $extendedData->set('stripe_response', $data);

            $transaction->setProcessedAmount($data['amount'] / 100);
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
            $transaction->setReferenceNumber($data['id']);
            $transaction->setExtendedData($extendedData);

            if ($this->logger) {
                $this->logger->info(sprintf(
                    'Refund is successful for transaction "%s".',
                    $data['id']
                ));
            }

            return;
        }

        if ($this->logger) {
            $this->logger->info(sprintf(
                'Refund failed for transaction "%s" with message: %s.',
                $response->getTransactionReference(),
                $response->getMessage()
            ));
        }

        $ex = $this->handleError($response, $transaction);

        throw $ex;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     *
     * @return array
     */
    protected function getPurchaseParameters(FinancialTransactionInterface $transaction)
    {
        /**
         * @var \JMS\Payment\CoreBundle\Model\PaymentInterface $payment
         */
        $payment = $transaction->getPayment();

        /**
         * @var \JMS\Payment\CoreBundle\Model\PaymentInstructionInterface $paymentInstruction
         */
        $paymentInstruction = $payment->getPaymentInstruction();

        /**
         * @var \JMS\Payment\CoreBundle\Model\ExtendedDataInterface $data
         */
        $data = $transaction->getExtendedData();

        $transaction->setTrackingId($payment->getId());

        $parameters = array(
            'amount' => $payment->getTargetAmount(),
            'currency' => $paymentInstruction->getCurrency(),
            'description' => $data->get('description'),
            'token' => $data->get('token'),
        );

        if ($data->has('paymentMethod')) {
            $parameters['paymentMethod'] = $data->get('paymentMethod');
        }

        if ($data->has('confirm')) {
            $parameters['confirm'] = $data->get('confirm');
        }

        if ($data->has('returnUrl')) {
            $parameters['returnUrl'] = $data->get('returnUrl');
        }

        if ($data->has('destination')) {
            $parameters['destination'] = $data->get('destination');
        }

        if ($data->has('receiptEmail')) {
            $parameters['receipt_email'] = $data->get('receiptEmail');
        }

        if ($data->has('statementDescriptor')) {
            $parameters['statement_descriptor'] = $data->get('statementDescriptor');
        }

        if ($data->has('applicationFee')) {
            $parameters['applicationFee'] = $data->get('applicationFee');
        }

        if ($data->has('stripeVersion')) {
            $parameters['stripeVersion'] = $data->get('stripeVersion');
        }

        if ($data->has('connectedStripeAccountHeader')) {
            $parameters['connectedStripeAccountHeader'] = $data->get('connectedStripeAccountHeader');
        }

        if ($data->has('metadata')) {
            $parameters['metadata'] = $data->get('metadata');
        }

        return $parameters;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     *
     * @return array
     */
    protected function getConfirmParameters(FinancialTransactionInterface $transaction)
    {
        /**
         * @var \JMS\Payment\CoreBundle\Model\ExtendedDataInterface $data
         */
        $data = $transaction->getExtendedData();

        $parameters = array(
            'paymentIntentReference' => $transaction->getReferenceNumber(),
        );

        if ($data->has('stripeVersion')) {
            $parameters['stripeVersion'] = $data->get('stripeVersion');
        }

        if ($data->has('connectedStripeAccountHeader')) {
            $parameters['connectedStripeAccountHeader'] = $data->get('connectedStripeAccountHeader');
        }

        return $parameters;
    }

    /**
     * @param FinancialTransactionInterface $transaction
     *
     * @return array
     */
    protected function getCreditParameters(FinancialTransactionInterface $transaction)
    {
        /**
         * @var \JMS\Payment\CoreBundle\Model\CreditInterface $credit
         */
        $credit = $transaction->getCredit();

        /**
         * @var \JMS\Payment\CoreBundle\Model\PaymentInstructionInterface $paymentInstruction
         */
        $paymentInstruction = $credit->getPaymentInstruction();

        /**
         * @var \JMS\Payment\CoreBundle\Model\ExtendedDataInterface $data
         */
        $data = $transaction->getExtendedData();

        $transaction->setTrackingId($credit->getId());

        $parameters = array(
            'amount' => $credit->getTargetAmount(),
            'currency' => $paymentInstruction->getCurrency(),
            'transactionReference' => $data->get('charge_id')
        );

        // By default we will refund the application fee
        $parameters['refundApplicationFee'] = true;
        if ($data->has('refundApplicationFee')) {
            $parameters['refundApplicationFee'] = $data->get('refundApplicationFee');
        }

        if ($data->has('stripeVersion')) {
            $parameters['stripeVersion'] = $data->get('stripeVersion');
        }

        if ($data->has('connectedStripeAccountHeader')) {
            $parameters['connectedStripeAccountHeader'] = $data->get('connectedStripeAccountHeader');
        }

        if ($data->has('metadata')) {
            $parameters['metadata'] = $data->get('metadata');
        }

        return $parameters;
    }

    /**
     * @param AbstractResponse               $response
     * @param FinancialTransactionInterface $transaction
     *
     * @return FinancialException
     */
    private function handleError(AbstractResponse $response, FinancialTransactionInterface $transaction)
    {
        $data = $response->getData();

        switch ($data['error']['type']) {
            case "api_error":
                $ex = new FinancialException($response->getMessage());
                $ex->addProperty('error', $data['error']);
                $ex->setFinancialTransaction($transaction);

                $transaction->setResponseCode('FAILED');
                $transaction->setReasonCode(substr($response->getMessage(), 0, 100));
                $transaction->setState(FinancialTransactionInterface::STATE_FAILED);

                break;

            case "card_error":
                $ex = new FinancialException($response->getMessage());
                $ex->addProperty('error', $data['error']);
                $ex->setFinancialTransaction($transaction);

                $transaction->setResponseCode('FAILED');
                $transaction->setReasonCode(substr($response->getMessage(), 0, 100));
                $transaction->setState(FinancialTransactionInterface::STATE_FAILED);

                break;

            default:
                $ex = new FinancialException($response->getMessage());
                $ex->addProperty('error', $data['error']);
                $ex->setFinancialTransaction($transaction);

                $transaction->setResponseCode('FAILED');
                $transaction->setReasonCode(substr($response->getMessage(), 0, 100));
                $transaction->setState(FinancialTransactionInterface::STATE_FAILED);

                break;
        }

        return $ex;
    }
}
