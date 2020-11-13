<?php

/**
 * @Copyright: Copyright :copyright: 2019 by IBPort. All rights reserved.
 * @Author: Neal Wong
 * @Email: ibprnd@gmail.com
 */

namespace Click2Mail;

use Click2Mail\Click2MailAPIRest;


class Click2MailClient {
	public const DFT_DOCUMENT_CLASS = 'Letter 8.5 x 11';
	public const DFT_LAYOUT = 'Picture and Address First Page';
	public const DFT_PRODUCTION_TIME = 'Next Day';
	public const DFT_ENVELOPE = '#10 Open Window Envelope';
	public const DFT_PAPER_TYPE = 'White 24#';
	public const DFT_MAIL_CLASS = 'First Class';
	public const FULL_COLOR = 'Full Color';
	public const BLACK_WHITE = 'Black and White';
	public const DUPLEX = 'Printing both sides';
	public const SINGLE = 'Printing One side';

	protected $api;

	public function __construct($username, $password, $live) {
		$this->api = new Click2MailAPIRest($username, $password, $live);
	}

 	public function send($addresses, $document, $returnAddress, $scheduledAt, $options = []) {
 		$this->addAddresses($addresses);

 		$formatedDocument = $this->getDocument($document);
 		if (!$formatedDocument) {
 			$message = sprintf('No correct document find - %s', json_encode($document));
 			$result = (object) ['status' => 10000, 'description' => $message];

 			return $result;
 		}

 		if (!isset($formatedDocument['class']) || !$formatedDocument['class']) {
 			$formatedDocument['class'] = $options['document_class'] ?? self::DFT_DOCUMENT_CLASS;
 		}

 		$response = $this->createDocument($formatedDocument);
 		if (property_exists($response, 'status') && $response->status > 0) {
 			$message = sprintf(
 				'[CreateDocumentFailure] Status: %s, Message: %s',
 				$response->status, $response->description
 			);
 			return (object) ['status' => 10001, 'description' => $message];
 		}

 		if (!isset($options['return_address'])) {
 			$options['return_address'] = $returnAddress;
 		}
 		if (!isset($options['production_time'])) {
 			$options['production_time'] = $scheduledAt;
 		}

 		$response = $this->createAndSubmitJob($options);

 		$this->api->clearJob();

 		return $response;
 	}

 	public function getJobStatus($jobId) {
 		return $this->api->job_checkStatus($jobId);
 	}

 	public function getAccountCredit() {
 		return $this->api->getCredit();
 	}

 	public function getAccountReturnAddresses() {
		return $this->api->getAccountAddresses('Return address');
	}

 	public function addAddresses($addresses) {
 		foreach ($addresses as $address) {
 			$companyName = isset($address['company']) ? $address['company'] : '';
 			$this->api->addAddress(
 				$address['first_name'], $address['last_name'], $companyName,
 				$address['address1'], $address['address2'], $address['city'],
 				$address['state'], $address['zip_code'], $address['country']
 			);
 		}
 		$addressesXml = $this->api->createAddressList();

 		return $this->api->addressList_create($addressesXml);
 	}

 	public function getDocument($document) {
 		if (!isset($document['files']) || empty($document['files'])) {
	      return FALSE;
	    }
	    $file = current($document['files']);
	    if (!isset($file['path'])) {
	      return FALSE;
	    }

	    // $filePath = realpath($file['path']);
	    $filePath = $file['path'];
	    if (!is_file($filePath)) {
	    	return FALSE;
	    }

	    $documentClass = isset($file['class']) ? $file['class'] : NULL;
	    if ($documentClass) {
	    	$result = ['file' => $filePath, 'class' => $documentClass];
	    } else {
	    	$result = ['file' => $filePath];
	    }

	    return $result;
 	}

 	public function createDocument($document) {
	    return $this->api->document_create($document['file'], $document['class']);
 	}

 	public function createAndSubmitJob($options) {
 		$documentClass = $options['document_class'] ?? self::DFT_DOCUMENT_CLASS;
 		$layout = $options['layout'] ?? self::DFT_LAYOUT;
 		$productionTime = $options['production_time'] ?? self::DFT_PRODUCTION_TIME;
 		$envelope = $options['envelope'] ?? self::DFT_ENVELOPE;
 		$paperType = $options['paper_type'] ?? self::DFT_PAPER_TYPE;
 		if (isset($options['colored']) && $options['colored']) {
 			$color = self::FULL_COLOR;
 		} else {
 			$color = self::BLACK_WHITE;
 		}
 		if (isset($options['duplex']) && $options['duplex']) {
 			$printOption = self::DUPLEX;
 		} else {
 			$printOption = self::SINGLE;
 		}
 		$returnAddress = $options['return_address'] ?? NULL;

 		print('Creating JOB...');
 		$response = $this->api->job_create(
			$documentClass, $layout, $productionTime, $envelope, $color,
			$paperType, $printOption, $returnAddress);
 		if (property_exists($response, 'status') && $response->status > 0) {
 			$message = sprintf(
 				'[CreateJobFailure] Status: %s, Message: %s',
 				$response->status, $response->description
 			);
 			throw new \Exception($message);
 		}

		print('Submitting JOB...');
		return $this->api->job_Submit();
 	}
}
