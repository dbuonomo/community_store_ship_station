<?php

/**
 * ShipStation for Community Store
 *
 * @package     Public
 * @subpackage  Community Store ShipStation
 * @author      David Buonomo <dnb@blueatlas.com>
 * @copyright   2018 David Buonomo (c)
 * @version     1.0
 * @license     The MIT License (MIT)
 *
 */

namespace Concrete\Package\CommunityStoreShipStation\Controller;

use Core;
use Controller;
use Session;
use Config;
use Log;
use Database;
use Concrete\Core\Error\Error;
use DateTime;
use DateInterval;
use SimpleXMLElement;
use Exception;
use Response;

use Concrete\Package\CommunityStore\Src\CommunityStore\Customer\Customer as StoreCustomer;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order as StoreOrder;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatusHistory as StoreOrderStatusHistory;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList as StoreOrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

class ShipStation extends Controller
{
    /**
     * Authenticate the request using HTTP Basic auth.
     *
     * @return bool, true if successfully authenticated or false otherwise
     */
    protected function basic_auth()
    {
        if (!Config::get('ship_station.auth.enabled')) {
            return true;
        }

        $expectedUsername = Config::get('ship_station.auth.username');
        $expectedPassword = Config::get('ship_station.auth.password');

        $username = array_key_exists('PHP_AUTH_USER', $_SERVER) ? $_SERVER['PHP_AUTH_USER'] : null;
        $password = array_key_exists('PHP_AUTH_PW', $_SERVER) ? $_SERVER['PHP_AUTH_PW'] : null;

        return $expectedUsername === $username && $expectedPassword === $password;
    }

    public function response()
    {
        try {
            if (!$this->basic_auth()) {
                \Log::addError(t('ShipStation: basic authentiation failed - invalid username or password.'));
                return new Response(t('401 Unauthorized'), Response::HTTP_UNAUTHORIZED);
            }

            // @TODO: validate
            $action = $_GET['action'];

            switch ($action) {
                case 'export':
                    $response = $this->export();
                    break;
                case 'shipnotify':
                    $response = $this->shipnotify();
                    break;
                default:
                    \Log::addError(t('ShipStation: invalid action URL parameter.'));
                    return new Response(t('400 Bad Request'), Response::HTTP_BAD_REQUEST);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        return $response;
    }

    private function export()
    {
        // @TODO: validate
        $start_date = $_GET['start_date'];

        // @TODO: validate
        $end_date = $_GET['end_date'];

        // @TODO: validate
        $page = $_GET['page'];

        try {
            $xmlDoc = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>' . '<Orders />', LIBXML_NOERROR);

            $ol = new StoreOrderList();
            //$ol->setStatus('nodelivery');
            $ol->setStatus('incomplete');
            //$ol->setFromDate($dateFrom);
            //$ol->setToDate($dateTo);
            $ol->setToDate((new DateTime)->add(new DateInterval('P21D'))->format('Y-m-d'));
            $ol->setPaid(true);
            $ol->setCancelled(false);

            // @TODO: Implement support for pagination in XML response
            $ol->setItemsPerPage(Config::get('ship_station.orders_per_page'));
            $paginator = $ol->getPagination();
            $orders = $paginator->getCurrentPageResults();

            if (Config::get('ship_station.mode') != 'test' && count($orders) > 0) {
                $xmlDoc->addAttribute('pages', 1);

                foreach ($orders as $order) {
                    $this->addOrder($xmlDoc, $order);

                    // @TODO: Update status only on successful return from addOrder()
                    StoreOrderStatusHistory::updateOrderStatusHistory($order, 'processing');
break;
                }
            } else {
                $xmlDoc->addAttribute('pages', 0);

                \Log::addNotice(t('ShipStation: no orders to export.'));
            }

            $dom = dom_import_simplexml($xmlDoc)->ownerDocument;
            $dom->formatOutput = true;

            $fName = 'export-' . date("Y-m-d\THis") . '.xml';
            $osName = 'application/files/ship_station/' . $fName;
            $urlName = rtrim(Core::getApplicationURL(), '\\/') . '/' . $osName;

            if (!file_exists($osName)) {
                @touch($osName);
            }
            if (!is_writable($osName)) {
                throw new Exception(t('File %s is not writable.', $osName));
            }
            if (!$hFile = @fopen($osName, 'w')) {
                throw new Exception(t('Cannot open file %s.', $osName));
            }
            if (!@fwrite($hFile, html_entity_decode($dom->saveXML()))) {
                throw new Exception(t('Error writing to file %s.', $osName));
            }
            @fflush($hFile);
            @fclose($hFile);
            unset($hFile);

            \Log::addNotice(t('ShipStation: export request - %1$s', $fName));

            //$dom->formatOutput = false;
            $response = new Response(
                html_entity_decode($dom->saveXML()),
                200,
                ['Content-Type' => 'text/xml']
            );
        } catch (Exception $e) {
            if (isset($hFile) && $hFile) {
                @fflush($hFile);
                @ftruncate($hFile, 0);
                @fclose($hFile);
                $hFile = null;
            }
            throw $e;
        }

        return $response;
    }

/*
<OrderID><![CDATA[123456]]></OrderID>
<OrderNumber><![CDATA[ABC123]]></OrderNumber>
<OrderDate>12/8/2011 21:56 PM</OrderDate>
<OrderStatus><![CDATA[paid]]></OrderStatus>
<LastModified>12/8/2011 12:56 PM</LastModified>
<ShippingMethod><![CDATA[USPSPriorityMail]]></ShippingMethod>
<PaymentMethod><![CDATA[Credit Card]]></PaymentMethod>
<OrderTotal>123.45</OrderTotal>
<TaxAmount>0.00</TaxAmount>
<ShippingAmount>4.50</ShippingAmount>
<CustomerNotes><![CDATA[Please make sure it gets here by Dec. 22nd!]]></CustomerNotes>
<InternalNotes><![CDATA[Ship by December 18th via Priority Mail.]]></InternalNotes>
<Gift>false</Gift>
<GiftMessage></GiftMessage>
<CustomField1></CustomField1>
<CustomField2></CustomField2>
<CustomField3></CustomField3>
<Customer>
    <CustomerCode><![CDATA[customer@mystore.com]]></CustomerCode>
    <BillTo>
        <Name><![CDATA[The President]]></Name>
        <Company><![CDATA[US Govt]]></Company>
        <Phone><![CDATA[512-555-5555]]></Phone>
        <Email><![CDATA[customer@mystore.com]]></Email>
    </BillTo>
    <ShipTo>
        <Name><![CDATA[The President]]></Name>
        <Company><![CDATA[US Govt]]></Company>
        <Address1><![CDATA[1600 Pennsylvania Ave]]></Address1>
        <Address2></Address2>
        <City><![CDATA[Washington]]></City>
        <State><![CDATA[DC]]></State>
        <PostalCode><![CDATA[20500]]></PostalCode>
        <Country><![CDATA[US]]></Country>
        <Phone><![CDATA[512-555-5555]]></Phone>
    </ShipTo>
</Customer>
<Items>
    <Item>
        <SKU><![CDATA[FD88821]]></SKU>
        <Name><![CDATA[My Product Name]]></Name>
        <ImageUrl><![CDATA[http://www.mystore.com/products/12345.jpg]]></ImageUrl>
        <Weight>8</Weight>
        <WeightUnits>Ounces</WeightUnits>
        <Quantity>2</Quantity>
        <UnitPrice>13.99</UnitPrice>
        <Location><![CDATA[A1-B2]]></Location>
        <Options>
            <Option>
                <Name><![CDATA[Size]]></Name>
                <Value><![CDATA[Large]]></Value>
                <Weight>10</Weight>
            </Option>
            <Option>
                <Name><![CDATA[Color]]></Name>
                <Value><![CDATA[Green]]></Value>
                <Weight>5</Weight>
            </Option>
        </Options>
    </Item>
    <Item>
        <SKU></SKU>
        <Name><![CDATA[$10 OFF]]></Name>
        <Quantity>1</Quantity>
        <UnitPrice>-10.00</UnitPrice>
        <Adjustment>true</Adjustment>
    </Item>
</Items>
*/

    /**
     * Adds an order to the XML data
     *
     * @param $xmlDoc The XML document object
     * @param $order The order object to add to the XML doc
     *
     * @throws Exception Throws an exception in case of errors.
     */
    private function addOrder($xmlDoc, $order)
    {
        try {
            $customer = new StoreCustomer();
            $user = $customer->getUserInfo();

            // Order node
            $orderNode = $xmlDoc->addChild('Order');

            $orderNode->addChild('OrderID', $order->getOrderID());
            $orderNode->addChild('OrderNumber', $order->getOrderID());
            $orderNode->addChild('OrderDate', $order->getOrderDate()->format('m/d/Y H:i A'));
            $orderNode->addChild('OrderStatus', '<![CDATA[' . $order->getStatusHandle() . ']]>');
            $orderNode->addChild('LastModified', $order->getOrderDate()->format('m/d/Y H:i A'));
            // @TODO: determine best option for retrieving shipping method
            //$orderNode->addChild('ShippingMethod', '<![CDATA[' . $order->getCarrier() . ']]>');
            $orderNode->addChild('ShippingMethod', '<![CDATA[' . $order->getShippingMethodName() . ']]>');
            $orderNode->addChild('PaymentMethod', '<![CDATA[' . $order->getPaymentMethodName() . ']]>');
            $orderNode->addChild('OrderTotal', $order->getSubTotal());
            // @TODO: check if use of Price::formatFloat is best for rounding tax
            $orderNode->addChild('TaxAmount', Price::formatFloat($order->getTaxTotal()));
            $orderNode->addChild('ShippingAmount', '0.00');
            $orderNode->addChild('CustomerNotes', '<![CDATA[]]>');
            $orderNode->addChild('InternalNotes', '<![CDATA[]]>');
            $orderNode->addChild('Gift', 'false');
            $orderNode->addChild('GiftMessage', '<![CDATA[]]>');

            // Order url
            // @TODO: add selection lists for custom fields to dashboard view
            $orderNode->addChild('CustomField1', '//' . $_SERVER['HTTP_HOST'] . '/dashboard/store/orders/order/' . $order->getOrderID());
            $orderNode->addChild('CustomField2', 'TBD');
            $orderNode->addChild('CustomField3', 'TBD');

            // Customer node
            $customerNode = $orderNode->addChild('Customer');
            $customerNode->addChild('CustomerCode', '<![CDATA[' . $order->getAttribute("email") . ']]>');

            // BillTo node
            $billToNode = $customerNode->addChild('BillTo');
            $billToNode->addChild('Name', '<![CDATA[' . $order->getAttribute("billing_first_name") . ' ' . $order->getAttribute("billing_last_name") . ']]>');
            $billToNode->addChild('Company', '<![CDATA[N/A]]>');
            $billToNode->addChild('Phone', '<![CDATA[' . $order->getAttribute("billing_phone") . ']]>');
            $billToNode->addChild('Email', '<![CDATA[' . $order->getAttribute("email") . ']]>');

            // ShipTo node
            $shipToNode = $customerNode->addChild('ShipTo');
            $shipToNode->addChild('Name', '<![CDATA[' . $order->getAttribute('shipping_first_name') . " " . $order->getAttribute('shipping_last_name') . ']]>');
            $shipToNode->addChild('Company', '<![CDATA[N/A]]>');
            $shipToNode->addChild('Address1', '<![CDATA[' . $order->getAttribute("shipping_address")->address1 . ']]>');
            $shipToNode->addChild('Address2', '<![CDATA[' . $order->getAttribute("shipping_address")->address2 . ']]>');
            $shipToNode->addChild('City', '<![CDATA[' . $order->getAttribute('shipping_address')->city . ']]>');
            $shipToNode->addChild('State', '<![CDATA[' . $order->getAttribute('shipping_address')->state_province . ']]>');
            $shipToNode->addChild('PostalCode', '<![CDATA[' . $order->getAttribute('shipping_address')->postal_code . ']]>');
            $shipToNode->addChild('Country', 'US');
            $shipToNode->addChild('Phone', '<![CDATA[' . $order->getAttribute('billing_phone') . ']]>');

            // Items node
            $itemsNode = $orderNode->addChild('Items');
            $items = $order->getOrderItems();

            foreach ($items as $item) {
                $product = $item->getProductObject();

                if ($product->isShippable()) {
                    // Item node
                    $itemNode = $itemsNode->addChild('Item');
                    $itemNode->addChild('SKU', '<![CDATA[' . $item->getSKU() . ']]>');
                    $itemNode->addChild('Name', '<![CDATA[' . $item->getProductName() . ']]>');
                    $itemNode->addChild('ImageUrl', '<![CDATA[//' . $_SERVER['HTTP_HOST'] . ']]>');
                    $itemNode->addChild('Weight', $product->getWeight());
                    $itemNode->addChild('WeightUnits', 'Pounds');
                    $itemNode->addChild('Quantity', $item->getQty());
                    $itemNode->addChild('UnitPrice', $item->getPricePaid());
                    $itemNode->addChild('Location', '<![CDATA[N/A]]>');
                }
            }

            // @TODO: What does this do?
            if ((!empty($ret)) && ($ret < 0)) {
                for ($i = count($xmlDoc->url) - 1; $i >= 0; --$i) {
                    if ($xmlDoc->url[$i] == $orderNode) {
                        unset($xmlDoc->url[$i]);
                        break;
                    }
                }
            }
        }
        catch (Exception $e) {
            //TODO: add logging and exception handling
        }
    }

    /**
     * Receive and process a shipment notification.
     *
     * <ShipNotice>
     *     <OrderNumber>ABC123</OrderNumber>
     *     <OrderID>123456</OrderID>
     *     <CustomerCode>customer@mystore.com</CustomerCode>
     *     <CustomerNotes></CustomerNotes>
     *     <InternalNotes></InternalNotes>
     *     <NotesToCustomer></NotesToCustomer>
     *     <NotifyCustomer></NotifyCustomer>
     *     <LabelCreateDate>12/8/2011 12:56</LabelCreateDate>
     *     <ShipDate>12/8/2011</ShipDate>
     *     <Carrier>USPS</Carrier>
     *     <Service>Priority Mail</Service>
     *     <TrackingNumber>1Z909084330298430820</TrackingNumber>
     *     <ShippingCost>4.95</ShippingCost>
     *     <CustomField1></CustomField1>
     *     <CustomField2></CustomField2>
     *     <CustomField3></CustomField3>
     *     <Recipient>
     *         <Name>The President</Name>
     *         <Company>US Govt</Company>
     *         <Address1>1600 Pennsylvania Ave</Address1>
     *         <Address2></Address2>
     *         <City>Washington</City>
     *         <State>DC</State>
     *         <PostalCode>20500</PostalCode>
     *         <Country>US</Country>
     *     </Recipient>
     *     <Items>
     *         <Item>
     *             <SKU>FD88821</SKU>
     *             <Name>My Product Name</Name>
     *             <Quantity>2</Quantity>
     *             <LineItemID>25590</LineItemID>
     *         </Item>
     *     </Items>
     * </ShipNotice>
     *
     * @return string, HTTP response
     */

    private function shipnotify()
    {
        try {
            // @TODO: consider getting all data from XML payload

            // @TODO: validate
            $order_number = $_GET['order_number'];

            // @TODO: validate
            $carrier = $_GET['carrier'];

            // @TODO: validate
            $service = $_GET['service'];

            // @TODO: validate
            $tracking_number = $_GET['tracking_number'];

            // Post data contains shipment details
            $postData = file_get_contents('php://input');
            $xml = simplexml_load_string($postData);
            //$dom = dom_import_simplexml($xml)->ownerDocument;
            //$dom->formatOutput = true;
            //file_put_contents("/tmp/ss.txt", $dom->saveXML());

            $order = StoreOrder::getByID($order_number);
            if ($order) {
                $order->setCarrier($carrier);
                $order->setTrackingCode($tracking_number);
                $order->setTrackingURL($this->trackingUrl($carrier) . $tracking_number);
                StoreOrderStatusHistory::updateOrderStatusHistory($order, 'shipped');
            }

            $response = new Response(
                html_entity_decode('Success'),
                200,
                ['Content-Type' => 'text/html']
            );
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    /**
     * Get the carrier tracking URL.
     *
     * @param $carrier The shipment carrier (USPS, UPS, etc.)
     * 
     * @return string
     */
    private function trackingUrl($carrier)
    {
        if ($carrier === "USPS") {
            return "https://tools.usps.com/go/TrackConfirmAction?tLabels=";
        } else if ($carrier === "UPS") {
            return "https://wwwapps.ups.com/tracking/tracking.cgi?tracknum=";
        } else {
            return "";
        }
    }
}

