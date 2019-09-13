<?php

namespace Ruudk\Payment\StripeBundle\Plugin;

use JMS\Payment\CoreBundle\Entity\ExtendedData;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use Omnipay\Common\Message\AbstractResponse;
use Omnipay\Stripe\Gateway;
use Psr\Log\LoggerInterface;

class CheckoutPlugin extends AbstractPlugin
{
    /**
     * @var \Omnipay\Stripe\Gateway
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

    public function __construct(Gateway $gateway)
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

    public function setProcessesType($processesType)
    {
        $this->processesType = $processesType;
    }

    public function processes($name)
    {
        return $name == $this->processesType;
    }

    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $parameters = $this->getPurchaseParameters($transaction);

        $response = $this->gateway->purchase($parameters)->send();

        if ($this->logger) {
            $this->logger->info(json_encode($response->getRequest()->getData()));
            $this->logger->info(json_encode($response->getData()));
        }

        if (array_key_exists('id', $response->getData())) {
            $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('stripe_charge_id', $response->getTransactionReference());
        }

        if (array_key_exists('balance_transaction', $response->getData())) {
            $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('balance_transaction_id', $response->getBalanceTransactionReference());
        }

        if (array_key_exists('application_fee', $response->getData())) {
            $transaction->getPayment()->getPaymentInstruction()->getExtendedData()->set('application_fee_id', $response->getData()['application_fee']);
        }

        if ($response->isSuccessful()) {
            $transaction->setReferenceNumber($response->getTransactionReference());

            $data = $response->getData();

            $transaction->setProcessedAmount($data['amount'] / 100);
            $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
            $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);

            if ($this->logger) {
                $this->logger->info(sprintf(
                    'Payment is successful for transaction "%s".',
                    $response->getTransactionReference()
                ));
            }

            return;
        }

        if ($this->logger) {
            $this->logger->info(sprintf(
                'Payment failed for transaction "%s" with message: %s.',
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
            'token' => $data->get('token'),
            'transactionReference' => $data->get('stripe_charge_id')
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
