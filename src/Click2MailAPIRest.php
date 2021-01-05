<?php

namespace Click2Mail;


class Click2MailAPIRest
{
	public static $LRestmainurl = "https://rest.click2mail.com";
	public static $sRestmainurl = "https://stage-rest.click2mail.com";

	public static $mimeTypes = array(
        'txt' => 'text/plain',
        'htm' => 'text/html',
        'html' => 'text/html',
        'php' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'swf' => 'application/x-shockwave-flash',
        'flv' => 'video/x-flv',

        // images
        'png' => 'image/png',
        'jpe' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'ico' => 'image/vnd.microsoft.icon',
        'tiff' => 'image/tiff',
        'tif' => 'image/tiff',
        'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml',

        // archives
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        'exe' => 'application/x-msdownload',
        'msi' => 'application/x-msdownload',
        'cab' => 'application/vnd.ms-cab-compressed',

        // audio/video
        'mp3' => 'audio/mpeg',
        'qt' => 'video/quicktime',
        'mov' => 'video/quicktime',

        // adobe
        'pdf' => 'application/pdf',
        'psd' => 'image/vnd.adobe.photoshop',
        'ai' => 'application/postscript',
        'eps' => 'application/postscript',
        'ps' => 'application/postscript',

        // ms office
        'doc' => 'application/msword',
        'rtf' => 'application/rtf',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',

        // open office
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    );

	protected $mode = 0;
	protected $username;
	protected $password;
	protected $addresses;
	public $documentId = 0;
	public $addressListId = 0;
	public $addressListStatus = 0;
	public $jobId = 0;

	public function __construct($username, $password, $live) {
		$this->username = $username;
		$this->password = $password;
		$this->addresses = new Addresses();
		$this->mode = strtolower($live) == "live" ? 1 : 0;
	}

    public function getMimeType($filename) {
        $ext = strtolower(array_pop(explode('.', $filename)));
        $mimeType = 'application/octet-stream';
        if (array_key_exists($ext, $mime_types)) {
            $mimeType = self::$mimeTypes[$ext];
        } elseif (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME);
            $mimeType = finfo_file($finfo, $filename);
            finfo_close($finfo);
        }

        return $mimeType;
    }

	public function job_checkStatus($jobId) {
		$ar = array();
		$url = $this->get_restUrl() . "/molpro/jobs/" . $jobId;
		$output =$this->rest_Call2($url, $ar, "GET");

		return $output;
	}

	public function addressList_GetStatus() {  
		$url = $this->get_restUrl() . "/molpro/addressLists/" . $this->addressListId;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		$output = curl_exec($ch);

		if ($output === FALSE) {
			$response = (object) [
				'errno' => curl_errno($ch),
				'message' => curl_error($ch)
			];
		} else {
			$xml = simplexml_load_string($output);
			if ($xml) {
				$response = $xml;
			} else {
				$message = '[XMLParseFailure] ' . $output . '\n';
				foreach (libxml_get_errors() as $error) {
					$message .= $error->message . '\n';
				}
				$response = (object) [
					'errno' => -1,
					'message' => $message
				];
			}
		}

		curl_close($ch);

		return $response;
	}

	public function document_create($file, $documentClass) {
		$docName = "PHP SDK " . substr(md5(rand()), 0, 7);
		$format = strtoupper(pathinfo($file, PATHINFO_EXTENSION));
		$ar = array(
			'documentName' => $docName,
			"documentClass" => $documentClass,
			"documentFormat" => $format,
			"file" => new \CURLFile($file)
		);

		$url = $this->get_restUrl() . "/molpro/documents/";
		$xmlDoc = $this->rest_Call($url, $ar, "POST");
		$this->documentId = (string) $xmlDoc->id;

		return $xmlDoc;
	}
	
	public function addressList_create($xml) {
		$url = $this->get_restUrl() . "/molpro/addressLists/";
		$xmlDoc = $this->rest_UploadXML($url, $xml);

		$this->addressListStatus =  (string) $xmlDoc->status;

		if($this->addressListStatus == 9) {
			print_r($xmlDoc);
		}
		$this->addressListId = (string) $xmlDoc->id;

		while ($this->addressListStatus != "3") {
			$message = sprintf(
				"Waiting Address List to finish processes. Current Status is: %s\n\n",
				$this->addressListStatus);
			echo $message;

			usleep(5000000);

			$xmlDoc = $this->addressList_GetStatus();
			$this->addressListStatus = (string) $xmlDoc->status;
		}

		return $xmlDoc;
	}

	public function job_create(
		$documentClass, $layout, $productionTime, $envelope, $color,
		$paperType, $printOption, $returnAddress = NULL, $mailClass = 'First Class') {
		$ar = array(
			"documentClass" =>  $documentClass,
			"layout" => $layout,
			"productionTime" => $productionTime,
			"envelope" => $envelope,
			"color" => $color,
			"paperType" => $paperType,
			"printOption" => $printOption,
			"documentId" => $this->documentId,
			"addressId" => $this->addressListId,
			'mailClass' => $mailClass
		);
		if ($returnAddress) {
			if (is_array($returnAddress)) {
				$ar['rtnName'] = sprintf(
					'%s %s', $returnAddress['first_name'], $returnAddress['first_name']);
				$ar['rtnaddress1'] = $returnAddress['address1'];
				$ar['rtnCity'] = $returnAddress['city'];
				$ar['rtnState'] = $returnAddress['state'];
				$ar['rtnZip'] = $returnAddress['zip_code'];

				if (isset($returnAddress['company']) && !empty($returnAddress['company'])) {
					$ar['rtnOrganization'] = $returnAddress['company'];
				}
				if (isset($returnAddress['address2']) && !empty($returnAddress['address2'])) {
					$ar['rtnaddress2'] = $returnAddress['address2'];
				}
			} else if (is_string($returnAddress) or is_integer($returnAddress)) {
				$ar['returnAddressId'] = $returnAddress;
			}
			
		}
		print_r($ar);

		$url = $this->get_restUrl() . "/molpro/jobs/";
		$output =$this->rest_Call2($url, $ar, "POST");
		if (property_exists($output, 'id')) {
			$this->jobId = (string) $output->id;
		}

		return $output;
	}

	public function job_Submit() {
		$ar = array(
			"billingType" => "User Credit"
		);
		$url = $this->get_restUrl() . "/molpro/jobs/" . $this->jobId . "/submit/";
		$output = $this->rest_Call2($url, $ar, "POST");

		return $output;
	}

	public function runAll($documentClass, $layout, $productionTime, $envelope, $color, $paperType, $printOption, $file,$xml) {
		echo "Document Uploading\n\n";
		$this->document_create($file, $documentClass);

		echo "AddressList Uploading\n\n";
		$this->addressList_create($xml);

		echo "Job Create\n\n";
		$output = $this->job_create(
			$documentClass, $layout, $productionTime, $envelope, $color, $paperType, $printOption);
		print_r($output);

		echo "Job Submit\n\n";	
		$this->job_Submit();
		$output = $this->job_checkStatus($this->jobId);

		return $output;
	}

	public function addAddress(
		$first, $last, $org, $address1, $address2, $city, $state, $zip, $country) {
		$address = new Address();
		$address->First_name = $first;
		$address->Last_name = $last;
		$address->organization = $org;
		$address->Address1 = $address1;
		$address->Address2 = $address2;
		$address->City = $city;
		$address->State = $state;
		$address->Zip = $zip;
		$address->Country_nonDASHUS = $country;

		$this->addresses->addAddress($address);
	}
	
	public function clearJob() {
		$this->addresses = new Addresses();
		$this->jobId = 0;
		$this->addressListId = 0;
		$this->documentId= 0;
	}

	public function getAddressListInfo($addressListId) {
		$limit = 1000;
		$query = http_build_query(
			['baseAddressListId' => $addressListId, 'limit' => $limit]);
		$url = $this->get_restUrl() . '/molpro/addressLists/info?' . $query;
		$response = $this->sendRequest($url);

		return json_decode(json_encode($response));
	}

	public function getAddressLists() {
		$addressLists = [];
		$numberOfLists = 250;
		$offset = 0;
		$query = http_build_query(['numberOfLists' => $numberOfLists, 'offset' => 0]);

		while (TRUE) {
			$url = $this->get_restUrl() . '/molpro/addressLists?' . $query;
			$response = $this->sendRequest($url);

			if (property_exists($response, 'errno') && $response->errno > 0) {
				if (property_exists($response, 'message')) {
					$message = $response->message;
				} else {
					$message = 'Retrieve Address Lists failure!';
				}

				break;
			} else if (property_exists($response, 'status') && $response->status > 0) {
				$message = json_encode($response);

				break;
			}

			$response = json_decode(json_encode($response));
			$addressLists = array_merge($addressLists, $response->lists->list);

			$count = $response->count;
			$offset = $numberOfLists * ($offset + 1);
			if ($count <= $offset) {
				break;
			}

			$query = http_build_query(['numberOfLists' => $numberOfLists, 'offset' => 0]);
		}

		return $addressLists;
	}
	
	public function createAddressList() {
		$this->addressListxml = new \SimpleXMLElement('<addressList/>');
		$this->addressListxml->addChild('addressListName', "PHP SDK".substr(md5(rand()), 0, 7));
		$this->addressListxml->addChild('addressMappingId', '2');
		$addressesXml = $this->addressListxml->addChild('addresses');
		
		foreach ($this->addresses->addresses as $address) {
			$addressXml = $addressesXml->addChild('address');
			foreach($address as $key => $value) {
				$addressXml->addChild(str_ireplace("DASH", "-", $key), $value);
			}
		}	

		return $this->addressListxml->asXML();
	}

	public function createCustomAddressList($addressListArray, $addressMappingId){
		$this->addressListxml = new \SimpleXMLElement('<addressList/>');
		$this->addressListxml->addChild('addressListName', "PHP SDK".substr(md5(rand()), 0, 7));
		$this->addressListxml->addChild('addressMappingId', $addressMappingId);
		$addressesXml = $this->addressListxml->addChild('addresses');
		foreach ($addressListArray as $aa) {
			$addressXml = $addressesXml->addChild('address');
			foreach($aa as $key=>$value) {
				$addressXml->addChild($key, $value);
			}
		}	

		return $this->addressListxml->asXML();
	}

	public function getCredit() {
		$url = $this->get_restUrl() . '/molpro/credit';
		return $this->sendRequest($url);
	}

	public function getAccountAddresses($addressType) {
		$query = http_build_query(['addressType' => $addressType]);
		$url = $this->get_restUrl() . '/molpro/account/addresses?' . $query;
		return $this->sendRequest($url);
	}
	
	public function get_restUrl() {
		return $this->mode == 0 ? self::$sRestmainurl : self::$LRestmainurl;
	}

	public function sendRequest(
		$url, $method = 'GET', $data = NULL, $headers = NULL, $regular = FALSE) {
		$method = strtoupper($method);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		if ($regular && in_array($method, ['POST', 'PUT'])) {
			curl_setopt($ch, constant('CURLOPT_' . $method), 1);
		} else {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);

		if ($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		if ($method != 'GET' && $data) {
  	    	$payload = http_build_query($data);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); 
			curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		}

		$output = curl_exec($ch);

		if ($output === FALSE) {
			$response = (object) [
				'errno' => curl_errno($ch),
				'message' => curl_error($ch)
			];
		} else {
			if (strpos($output, "{") !== FALSE) {
				$output = $this->json2xml($output);
			}
			$xml = simplexml_load_string($output);

			if ($xml) {
				$response = $xml;
			} else {
				$message = '[XMLParseFailure] ' . $output . '\n';
				foreach (libxml_get_errors() as $error) {
					$message .= $error->message . '\n';
				}
				$response = (object) [
					'errno' => -1,
					'message' => $message
				];
			}
		}

		return $response;
	}

	public function rest_Call($url, $ar, $type) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
		//curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $ar); 
		$output = curl_exec($ch);

		if ($output === FALSE) {
			$response = (object) [
				'errno' => curl_errno($ch),
				'message' => curl_error($ch)
			];
		} else {
			if (strpos($output, "{") !== FALSE) {
				$output = $this->json2xml($output);
			}
			$xml = simplexml_load_string($output);

			if ($xml) {
				$response = $xml;
			} else {
				$message = '[XMLParseFailure] ' . $output . '\n';
				foreach (libxml_get_errors() as $error) {
					$message .= $error->message . '\n';
				}
				$response = (object) [
					'errno' => -1,
					'message' => $message
				];
			}
		}

		curl_close($ch);

		return $response;	
	}
	
	function rest_Call2($url, $ar, $type) {
  	    $fields_string = http_build_query($ar);
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string); 
		//curl_setopt($ch, CURLOPT_POST, 1);
		$output = curl_exec($ch);

		if ($output === FALSE) {
			$response = (object) [
				'errno' => curl_errno($ch),
				'message' => curl_error($ch)
			];
		} else {
			if (strpos($output, "{") !== FALSE) {
				$output = $this->json2xml($output);
			}
			$xml = simplexml_load_string($output);

			if ($xml) {
				$response = $xml;
			} else {
				$message = '[XMLParseFailure] ' . $output . '\n';
				foreach (libxml_get_errors() as $error) {
					$message .= $error->message . '\n';
				}
				$response = (object) [
					'errno' => -1,
					'message' => $message
				];
			}
		}

		curl_close($ch);

		return $response;	
	}

	function rest_UploadXML($url, $xml) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml']);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml); 
		$output = curl_exec($ch);

		if ($output === FALSE) {
			$response = (object) [
				'errno' => curl_errno($ch),
				'message' => curl_error($ch)
			];
		} else {
			if (strpos($output, "{") !== FALSE) {
				$output = $this->json2xml($output);
			}
			$xml = simplexml_load_string($output);
			if ($xml) {
				$response = $xml;
			} else {
				$message = '[XMLParseFailure] ' . $output . '\n';
				foreach (libxml_get_errors() as $error) {
					$message .= $error->message . '\n';
				}
				$response = (object) [
					'errno' => -1,
					'message' => $message
				];
			}
		}

		curl_close($ch);

		return $response;	
	}

	function json2xml($json) {
	    $a = json_decode($json);
	    $d = new \DOMDocument();
	    $c = $d->createElement("root");
	    $d->appendChild($c);

	    $t = function($v) {
	        $type = gettype($v);
	        switch($type) {
	            case 'integer': return 'number';
	            case 'double':  return 'number';
	            default: return strtolower($type);
	        }
	    };

	    $f = function($f, $c, $a, $s = FALSE) use ($t,$d) {
	        $c->setAttribute('type', $t($a));
	        if ($t($a) != 'array' && $t($a) != 'object') {
	            if ($t($a) == 'boolean') {
	                $c->appendChild($d->createTextNode($a ? 'true' : 'false'));
	            } else {
	                $c->appendChild($d->createTextNode($a));
	            }
	        } else {
	            foreach($a as $k => $v) {
	                if ($k == '__type' && $t($a) == 'object') {
	                    $c->setAttribute('__type', $v);
	                } else {
	                    if ($t($v) == 'object') {
	                        $ch = $c->appendChild($d->createElementNS(NULL, $s ? 'item' : $k));
	                        $f($f, $ch, $v);
	                    } else if ($t($v) == 'array') {
	                        $ch = $c->appendChild($d->createElementNS(NULL, $s ? 'item' : $k));
	                        $f($f, $ch, $v, TRUE);
	                    } else {
	                        $va = $d->createElementNS(NULL, $s ? 'item' : $k);
	                        if ($t($v) == 'boolean') {
	                            $va->appendChild($d->createTextNode($v ? 'true' : 'false'));
	                        } else {
	                            $va->appendChild($d->createTextNode($v));
	                        }
	                        $ch = $c->appendChild($va);
	                        $ch->setAttribute('type', $t($v));
	                    }
	                }
	            }
	        }
	    };
	    $f($f, $c, $a, $t($a) == 'array');

	    return $d->saveXML($d->documentElement);
	}
}


class Addresses {
	public $addresses = array();

	public function addAddress($address) {
		$this->addresses[] = $address;
	}
}


class Address {
	public $First_name;
	public $Last_name;
	public $Organization;
	public $Address1;
	public $Address2;
	public $Address3;
	public $City;
	public $State;
	public $Zip;
	public $Country_nonDASHUS;
}
