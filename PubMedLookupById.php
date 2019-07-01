<?php
/**
 * Created by PhpStorm.
 * User: moorejr5
 * Date: 3/21/2018
 * Time: 12:54 PM
 */

namespace Vanderbilt\PubMedLookupById;

use DateTime;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

class PubMedLookupById extends AbstractExternalModule
{
	function redcap_data_entry_form($project_id, $record, $instrument, $event_id, $group_id = NULL, $repeat_instance = 1) {
		global $Proj;
		echo $this->createCalcuationJava($Proj,$instrument,$record,$event_id,$repeat_instance);
	}

	function redcap_survey_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id = NULL, $repeat_instance = 1) {
		global $Proj;
		echo $this->createCalcuationJava($Proj,$instrument,$record,$event_id,$repeat_instance);
	}

	function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash = NULL, $response_id = NULL, $repeat_instance = 1)
    {
        global $Proj;

        $sourceField = $this->getProjectSetting('source');
        if (in_array($sourceField, array_keys($_POST)) && is_numeric($_POST[$sourceField])) {
            $destinationFields = $this->getProjectSetting('destination-field');
            $xmlFields = $this->getProjectSetting('source-field');
            $instrument = $_POST['instrument'];
#Get the list of fields that exist on the current form
            $fieldsOnForm = $Proj->forms[$instrument]['fields'];
            $recordData = \Records::getData($project_id, 'array', array($record), array_merge($destinationFields,array($sourceField)));
            $pubmedid = $recordData[$record][$event_id][$sourceField];

            if (is_numeric($pubmedid)) {
                $timeout = 5;
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&id=" . $pubmedid . "&retmode=xml&rettype=medline");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                $xmlData = curl_exec($ch);
                curl_close($ch);

                $xmlParse = simplexml_load_string($xmlData);
                $jsonParse = json_encode($xmlParse);
                $xmlArray = json_decode($jsonParse, true);
                $xmlDoc = new \DOMDocument();
                $xmlDoc->loadXML($xmlData);

                $fieldChecks = array();
                foreach ($destinationFields as $index => $destinationField) {
                    if (in_array($destinationField, array_keys($fieldsOnForm))) continue;
                    if (!isset($xmlFields[$index]) || $xmlFields[$index] == "") continue;
                    $fieldChecks[$index] = $destinationField;
                }
                $dataArray = $this->parseXMLData($fieldChecks, $xmlFields, $xmlDoc);

                $fieldsToSave = array();
                foreach ($dataArray as $field => $value) {
                    $fieldsToSave[$record][$event_id][$field] = $value;
                }

                $overwriteText = "overwrite";
                if (!empty($fieldsToSave)) {
                    $output = \Records::saveData($project_id, 'array', $fieldsToSave, $overwriteText);
                }
            }
        }
        exit;
    }

	/*function redcap_module_link_check_display($project_id, $link, $record, $instrument, $instance, $page) {
		if(\REDCap::getUserRights(USERID)[USERID]['design'] == '1'){
			return $link;
		}
		return null;
	}*/

	/*
	 * Generate the necessary Javascript code to get on-form data piping working.
	 * @param $project REDCap Class object.
	 * @param $instrument Form name of the current form.
	 * @param $record_id ID of record being viewed
	 * @param $event_id Event ID.
	 * @param $instance Instance currently being viewed
	 * @return String containing javascript code
	 */
	function createCalcuationJava(\Project $project,$instrument,$record_id,$event_id,$instance) {
		$sourceField = $this->getProjectSetting('source');
        $fieldsOnForm = $project->forms[$instrument]['fields'];

		if ($sourceField != "" && in_array($sourceField, array_keys($fieldsOnForm))) {
            $javaString = "<script>";
            $javaString .= "$('input[name=$sourceField]').blur(function() {
                $.ajax({
                    url: '" . $this->getUrl('ajax_pubmed.php') . "',
                    method: 'post',
                    data: {
                        'pubmedid': $(this).val(),
                        'instrument': '".$instrument."'
                    },
                    success: function(data) {
                        if (data != '') {
                            var array = JSON.parse(data);
                            jQuery.each(array,function(index,value) {
                                $('input[name='+index+'],textarea[name='+index+']').val(value);
                            });
                        }
                    }
                });
            });";
            $javaString .= "</script>";
        }
		return $javaString;
	}

	/*
	 * Determines the format that a date field needs to be saved within the database.
	 * @param $validation_type The type of date format for the field being examined.
	 * @return Date format string. Default of 'Y-m-d'
	 */
	function dateSaveFormat($validation_type) {
		$format = "Y-m-d";
		if (strpos($validation_type,"datetime_") !== false) {
			if (strpos($validation_type,"_seconds_") !== false) {
				$format = "Y-m-d H:i:s";
			}
			else {
				$format = "Y-m-d H:i";
			}
		}
		return $format;
	}

	/*
	 * Determine the correct date formatting based on a field's element validation.
	 * @param $elementValidationType The element validation for the data field being examined.
	 * @param $dataArray An array with key elements of potentially 'Year', 'Month', 'Day', 'Hour', 'Minute', and 'Year'
	 * @param $dateType The type of date being pulled from the XML Data
	 * @return Date format string
	 */
	function parseDateArrayForREDCapField($elementValidationType, $dataArray, $dateType) {
		$returnString = "";
		if (!isset($dataArray['Year']['value']) || !isset($dataArray['Month']['value']) || !isset($dataArray['Day']['value'])) return $returnString;
		switch ($elementValidationType) {
			case "date_mdy":
				$returnString = str_pad(str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT),2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value'];
				break;
			case "date_dmy":
                $returnString = str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value'];
				break;
			case "date_ymd":
                $returnString = $dataArray['Year']['value']."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT);
				break;
			case "datetime_mdy":
                $returnString = str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value']." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			case "datetime_dmy":
                $returnString = str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value']." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			case "datetime_ymd":
                $returnString = $dataArray['Year']['value']."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			case "datetime_seconds_mdy":
                $returnString = str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value']." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Second']['value'] != "" ? str_pad($dataArray['Second']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			case "datetime_seconds_dmy":
                $returnString = str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".$dataArray['Year']['value']." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Second']['value'] != "" ? str_pad($dataArray['Second']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			case "datetime_seconds_ymd":
                $returnString = $dataArray['Year']['value']."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Second']['value'] != "" ? str_pad($dataArray['Second']['value'],2,"0",STR_PAD_LEFT) : "00");
				break;
			default:
                $returnString = $dataArray['Year']['value']."-".str_pad($dataArray['Month']['value'],2,"0",STR_PAD_LEFT)."-".str_pad($dataArray['Day']['value'],2,"0",STR_PAD_LEFT)." ".($dataArray['Hour']['value'] != "" ? str_pad($dataArray['Hour']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Minute']['value'] != "" ? str_pad($dataArray['Minute']['value'],2,"0",STR_PAD_LEFT) : "00").":".($dataArray['Second']['value'] != "" ? str_pad($dataArray['Second']['value'],2,"0",STR_PAD_LEFT) : "00");
		}
		if ($dateType != "" && isset($dataArray[$dateType]['tags']) && !empty($dataArray[$dateType]['tags'])) {
		    $returnString .= " (";
		    $returnString .= implode(", ",$dataArray[$dateType]['tags']);
		    $returnString .= ")";
        }
		return $returnString;
	}

    function validateDate($date,$format='Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
        return $d && $d->format($format) === $date;
    }

    function showNode($allNodes,&$nodeArray,$attributeArray = array())
    {
        if (get_class($allNodes) == "DOMNodeList") {
            for ($i = 0; $i < $allNodes->length; $i++) {
                $parentMatch = false;
                $node = $allNodes->item($i);

                if (!empty($attributeArray)) {
                    foreach ($attributeArray as $tag => $tagValue) {
                        if ($node->hasAttribute($tag) && ($node->getAttribute($tag) == $tagValue || $tagValue == "")) {
                            $nodeArray[$node->nodeName]['tags'][$tag] = $node->getAttribute($tag);
                            $parentMatch = true;
                        }
                    }
                } else {
                    $parentMatch = true;
                }
                foreach ($node->childNodes as $child) {
                    $matchAtrribute = false;
                    if ($this->hasChild($child)) {
                        $this->shownode($child, $nodeArray, $attributeArray);
                    } else {
                        if ($child->nodeType == XML_ELEMENT_NODE) {
                            if (!empty($attributeArray) && !$parentMatch) {
                                foreach ($attributeArray as $tag => $tagValue) {
                                    if ($child->hasAttribute($tag) && ($child->getAttribute($tag) == $tagValue || $tagValue == "")) {
                                        $nodeArray[$child->nodeName]['tags'][$tag] = $child->getAttribute($tag);
                                        $matchAtrribute = true;
                                    }
                                }
                            } elseif ($parentMatch) {
                                $matchAtrribute = true;
                            }
                            if ($matchAtrribute) {
                                $nodeArray[$child->nodeName]['value'] = $child->nodeValue;
                            }
                        }
                    }
                }
                /*if ($node->nodeName == "#text" || $allNodes->length == 1) {
                    foreach ($node->childNodes as $child) {
                        if (hasChild($child)) {
                            showNode($child, $nodeArray, $attributeArray);
                        } elseif ($child->nodeType == XML_ELEMENT_NODE) {
                            $matchAtrribute = false;
                            if (!empty($attributeArray)) {
                                foreach ($attributeArray as $tag => $tagValue) {
                                    if ($child->hasAttribute($tag) && ($child->getAttribute($tag) == $tagValue || $tagValue == "")) {
                                        $nodeArray[$child->nodeName]['tags'][$tag] = $child->getAttribute($tag);
                                        $matchAtrribute = true;
                                        break;
                                    }
                                }
                            } else {
                                $matchAtrribute = true;
                            }
                            if ($matchAtrribute) {
                                $nodeArray[$child->nodeName]['value'] = $child->nodeValue;
                            }
                        } elseif ($child->nodeValue != "" && $child->nodeName != '#text') {
                            $nodeArray[$child->nodeName]['value'] = $child->nodeValue;
                            foreach ($attributeArray as $tag => $tagValue) {
                                if ($child->hasAttribute($tag) && ($child->getAttribute($tag) == $tagValue || $tagValue == "")) {
                                    $nodeArray[$child->nodeName]['tags'][$tag] = $child->getAttribute($tag);
                                    $matchAtrribute = true;
                                    break;
                                }
                            }
                        } else {
                            $nodeArray[$node->nodeName]['value'] = $node->nodeValue;
                            foreach ($attributeArray as $tag => $tagValue) {
                                if ($node->hasAttribute($tag) && ($node->getAttribute($tag) == $tagValue || $tagValue == "")) {
                                    $nodeArray[$node->nodeName]['tags'][$tag] = $node->getAttribute($tag);
                                    $matchAtrribute = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $nodeArray[$node->nodeName]['value'] = $node->nodeValue;
                    foreach ($attributeArray as $tag => $tagValue) {
                        if ($node->hasAttribute($tag) && ($node->getAttribute($tag) == $tagValue || $tagValue == "")) {
                            $nodeArray[$node->nodeName]['tags'][$tag] = $node->getAttribute($tag);
                            $matchAtrribute = true;
                            break;
                        }
                    }
                }*/
            }
        }
        elseif ($allNodes->nodeType == XML_ELEMENT_NODE) {
            if (!empty($attributeArray)) {
                foreach ($attributeArray as $tag => $tagValue) {
                    if ($allNodes->hasAttribute($tag) && ($allNodes->getAttribute($tag) == $tagValue || $tagValue == "")) {
                        $nodeArray[$allNodes->nodeName]['tags'][$tag] = $allNodes->getAttribute($tag);
                        $matchAtrribute = true;
                    }
                }
            } else {
                $matchAtrribute = true;
            }
            if ($matchAtrribute) {
                $nodeArray[$allNodes->nodeName]['value'] = $allNodes->nodeValue;
            }
        }
    }
    function hasChild($element) {
        if ($element->hasChildNodes()) {
            foreach ($element->childNodes as $cNode) {
                if ($cNode->nodeType == XML_ELEMENT_NODE) {
                    return true;
                }
            }
        }
        return false;
    }

    /*
	 * Parses the desired XML data points into a data array for REDCap.
	 * @param $destinationFields Array of REDCap fields that need to be mapped to the XML.
	 * @param $xmlFields Array of XML fields that need to be mapped to the destination fields. Indexes between $destinationFields and $xmlFields link them.
	 * @param $xmlDoc A PHP DOMDocument object that contains the XML information.
	 * @return Array of data fields and their calculated values.
	 */
    function parseXMLData($destinationFields,$xmlFields,$xmlDoc) {
	    global $Proj;
	    $returnArray = array();
        foreach ($destinationFields as $index => $destinationField) {
            $nodeArray = array();
            /*if (!in_array($destinationField,array_keys($fieldsOnForm))) continue;
            if (!isset($xmlFields[$index]) || $xmlFields[$index] == "") continue;*/
            $xmlParamaters = explode(",",$xmlFields[$index]);
            $destFieldEnum = $Proj->metadata[$destinationField]['element_validation_type'];
            $filters = array();
            if (isset($xmlParamaters[1]) && isset($xmlParamaters[2]) && $xmlParamaters[1] != "") {
                $filters[$xmlParamaters[1]] = $xmlParamaters[2];
            }

            switch ($xmlParamaters[0]) {
                case "Status":
                case "Owner":
                    $filterArray = array($xmlParamaters[0] => "");
                    $searcher = $xmlDoc->getElementsByTagName("MedlineCitation");
                    $item0 = $searcher->item(0);
                    if ($item0->hasAttributes()) {
                        foreach ($item0->attributes as $attr) {
                            if ($attr->nodeName == $xmlParamaters[0]) {
                                $returnArray[$destinationField] = $attr->nodeValue;
                            }
                        }
                    }
                    break;
                case "AuthorFull":
                case "AuthorShort":
                    $searcher = $xmlDoc->getElementsByTagName("Author");
                    $this->showNode($searcher,$nodeArray,$filters);

                    if ($xmlParamaters[0] == "AuthorFull") {
                        $returnArray[$destinationField] = $nodeArray['LastName']['value'].", ".$nodeArray['ForeName']['value'];
                    }
                    else {
                        $returnArray[$destinationField] = $nodeArray['LastName']['value']." ".$nodeArray['Initials']['value'];
                    }
                    break;
                case "Grant":
                    $searcher = $xmlDoc->getElementsByTagName("Grant");
                    $this->showNode($searcher,$nodeArray,$filters);
                    $returnArray[$destinationField] = $nodeArray['GrantID']['value']."/".$nodeArray['Agency']['value']."/".$nodeArray['Country']['value'];
                    break;
                case "PubDate":
                    $searcher = $xmlDoc->getElementsByTagName("PubDate");
                    $this->showNode($searcher,$nodeArray,$filters);
                    $returnArray[$destinationField] = $nodeArray['Month']['value']." ".$nodeArray['Year']['value'];
                case "DateCompleted":
                case "DateRevised":
                case "ArticleDate":
                case "PubMedPubDate":
                    $searcher = $xmlDoc->getElementsByTagName($xmlParamaters[0]);
                    $this->showNode($searcher,$nodeArray,$filters);
                    $returnArray[$destinationField] = $this->parseDateArrayForREDCapField($destFieldEnum, $nodeArray,$xmlParamaters[0]);
                    break;
                default:
                    $searcher = $xmlDoc->getElementsByTagName($xmlParamaters[0])->item(0);
                    $this->showNode($searcher,$nodeArray,$filters);
                    $returnArray[$destinationField] = $nodeArray[$xmlParamaters[0]]['value'].(isset($nodeArray[$xmlParamaters[0]]['tags']) && !empty($nodeArray[$xmlParamaters[0]]['tags']) ? " (".$nodeArray[$xmlParamaters[0]]['tags'][$xmlParamaters[1]].")" : "");
                    break;
            }
        }
        return $returnArray;
    }
}