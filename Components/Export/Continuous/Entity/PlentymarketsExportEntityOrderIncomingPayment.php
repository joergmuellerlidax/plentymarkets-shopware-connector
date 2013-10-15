<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */

require_once PY_SOAP . 'Models/PlentySoapObject/AddIncomingPayments.php';
require_once PY_SOAP . 'Models/PlentySoapRequest/AddIncomingPayments.php';
require_once PY_COMPONENTS . 'Export/PlentymarketsExportEntityException.php';

/**
 * PlentymarketsExportEntityOrderIncomingPayment provides the actual incoming payments export funcionality. Like the other export
 * entities this class is called in PlentymarketsExportController. It is important to deliver a valid order ID
 * to the constructor method of this class.
 * The data export takes place based on plentymarkets SOAP-calls.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportEntityOrderIncomingPayment
{

	/**
	 * Shopware order data
	 *
	 * @var array
	 */
	protected $order = array();

	/**
	 * plentymarkets order data (out of shopware)
	 *
	 * @var array
	 */
	protected $plentyOrder = array();

	/**
	 * Constructor method
	 *
	 * @param integer $orderID Shopware order id
	 * @throws Exception
	 */
	public function __construct($orderID)
	{
		$OrderResource = Shopware\Components\Api\Manager::getResource('Order');

		try
		{
			$this->order = $OrderResource->getOne($orderID);
		}
		catch (\Shopware\Components\Api\Exception\NotFoundException $E)
		{
			throw new PlentymarketsExportEntityException('The incoming payment could not be booked in plentymarkets because the shopware sales order (' . $orderID . ') wasn\'t found.');
		}

		$Result = Shopware()->Db()->query('
			SELECT
					*
				FROM plenty_order
				WHERE shopwareId = ?
		', array(
			$orderID
		));

		$plentyOrder = $Result->fetchObject();
		if (!is_object($plentyOrder) || (integer) $plentyOrder->plentyOrderId <= 0)
		{
			throw new PlentymarketsExportEntityException('The incoming payment could not be booked in plentymarkets because the sales order (' . $this->order['id'] . ') was not yet exported to plentymarkets.');
		}
		if (!is_null($plentyOrder->plentyOrderPaidTimestamp))
		{
			throw new PlentymarketsExportEntityException('The incoming payment of the sales order ' . $this->order['id'] . ' has already been exported to plentymarkets.');
		}

		$this->plentyOrder = $plentyOrder;
	}

	/**
	 * Books the incoming payment
	 */
	public function book()
	{
		$Request_AddIncomingPayments = new PlentySoapRequest_AddIncomingPayments();

		$Request_AddIncomingPayments->IncomingPayments = array();
		$Object_AddIncomingPayments = new PlentySoapObject_AddIncomingPayments();
		$Object_AddIncomingPayments->Amount = $this->order['invoiceAmount'];
		$Object_AddIncomingPayments->Currency = PlentymarketsMappingController::getCurrencyByShopwareID($this->order['currency']);
		$Object_AddIncomingPayments->CustomerEmail = $this->order['customer']['email'];
		$Object_AddIncomingPayments->CustomerID = $this->getCustomerId();
		$Object_AddIncomingPayments->CustomerName = $this->getCustomerName();
		$Object_AddIncomingPayments->MethodOfPaymentID = PlentymarketsMappingController::getMethodOfPaymentByShopwareID($this->order['paymentId']);
		$Object_AddIncomingPayments->OrderID = $this->plentyOrder->plentyOrderId;
		$Object_AddIncomingPayments->ReasonForPayment = sprintf('Shopware (OrderId: %u, CustomerId: %u)', $this->order['id'], $this->order['customerId']);

		if (empty($this->order['transactionId']))
		{
			$Object_AddIncomingPayments->TransactionID = $Object_AddIncomingPayments->ReasonForPayment;
		}
		else
		{
			$Object_AddIncomingPayments->TransactionID = $this->order['transactionId'];
		}

		if ($this->object['clearedDate'] instanceof DateTime)
		{
			$Object_AddIncomingPayments->TransactionTime = $this->order['clearedDate']->getTimestamp();
		}
		else
		{
			$Object_AddIncomingPayments->TransactionTime = time();
		}

		$Request_AddIncomingPayments->IncomingPayments[] = $Object_AddIncomingPayments;
		$Response_AddIncomingPayments = PlentymarketsSoapClient::getInstance()->AddIncomingPayments($Request_AddIncomingPayments);

		// Check for success
		if ($Response_AddIncomingPayments->Success)
		{
			PlentymarketsLogger::getInstance()->message('Sync:Order:IncomingPayment', 'The incoming payment of the sales order ' . $this->order['id'] . ' was booked in plentymarkets.');
			Shopware()->Db()->query('
					UPDATE plenty_order
						SET
							plentyOrderPaidStatus = 1,
							plentyOrderPaidTimestamp = NOW()
						WHERE shopwareId = ?
				', array(
				$this->order['id']
			));
		}
		else
		{
			throw new PlentymarketsExportEntityException('The incoming payment of the sales order ' . $this->order['id'] . ' was not booked in plentymarkets.');
		}
	}

	/**
	 * Returns the plentymarkets customer id
	 *
	 * @return integer
	 */
	protected function getCustomerId()
	{
		try
		{
			return PlentymarketsMappingController::getCustomerByShopwareID($this->order['billing']['id']);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			// Customer needs to be re-exported
			PlentymarketsLogger::getInstance()->message('Sync:Order:IncomingPayment', 'Re-exporting customer');
		}

		// Get the data
		$Customer = Shopware()->Models()->find('Shopware\Models\Customer\Customer', $this->order['customerId']);
		$BillingAddress = Shopware()->Models()->find('Shopware\Models\Order\Billing', $this->order['billing']['id']);

		// Export
		require_once PY_COMPONENTS . 'Export/Entity/PlentymarketsExportEntityCustomer.php';
		$PlentymarketsExportEntityCustomer = new PlentymarketsExportEntityCustomer($Customer, $BillingAddress);
		$PlentymarketsExportEntityCustomer->export();

		// Workaround - Exception should come directly from the above export
		try
		{
			return PlentymarketsMappingController::getCustomerByShopwareID($this->order['billing']['id']);
		}
		catch (PlentymarketsMappingExceptionNotExistant $E)
		{
			throw new PlentymarketsExportEntityException('The incoming payment of the sales order ' . $this->order['id'] . ' was not booked in plentymarkets because the customer id mapping is missing');
		}
	}

	/**
	 * Returns the full customer name
	 *
	 * @return string
	 */
	protected function getCustomerName()
	{
		return sprintf('%s %s', $this->order['billing']['firstName'], $this->order['billing']['lastName']);
	}
}