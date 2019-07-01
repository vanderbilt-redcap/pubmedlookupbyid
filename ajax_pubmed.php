<?php
$projectID = $_REQUEST['pid'];
if (isset($_POST['pubmedid']) && is_numeric($projectID) && is_numeric($_POST['pubmedid'])) {
    $pubmedid = $_POST['pubmedid'];
    $destinationFields = $module->getProjectSetting('destination-field');
    $xmlFields = $module->getProjectSetting('source-field');
    $instrument = $_POST['instrument'];

    $currentProject = new \Project($projectID);
#Get the list of fields that exist on the current form
    $fieldsOnForm = $currentProject->forms[$instrument]['fields'];

    $timeout = 5;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,"https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?db=pubmed&id=".$pubmedid."&retmode=xml&rettype=medline");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
    $xmlData = curl_exec($ch);
    curl_close($ch);

    $xmlParse = simplexml_load_string($xmlData);
    $jsonParse = json_encode($xmlParse);
    $xmlArray = json_decode($jsonParse,true);
    $xmlDoc = new DOMDocument();
    $xmlDoc->loadXML($xmlData);

    $fieldChecks = array();
    foreach ($destinationFields as $index => $destinationField) {
        if (!in_array($destinationField, array_keys($fieldsOnForm))) continue;
        if (!isset($xmlFields[$index]) || $xmlFields[$index] == "") continue;
        $fieldChecks[$index] = $destinationField;
    }
    $returnArray = $module->parseXMLData($destinationFields,$xmlFields,$xmlDoc);
    /*foreach ($destinationFields as $index => $destinationField) {
        $nodeArray = array();
        if (!in_array($destinationField,array_keys($fieldsOnForm))) continue;
        if (!isset($xmlFields[$index]) || $xmlFields[$index] == "") continue;
        $xmlParamaters = explode(",",$xmlFields[$index]);
        $destFieldEnum = $currentProject->metadata[$destinationField]['element_validation_type'];
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
                $module->showNode($searcher,$nodeArray,$filters);

                if ($xmlParamaters[0] == "AuthorFull") {
                    $returnArray[$destinationField] = $nodeArray['LastName']['value'].", ".$nodeArray['ForeName']['value'];
                }
                else {
                    $returnArray[$destinationField] = $nodeArray['LastName']['value']." ".$nodeArray['Initials']['value'];
                }
                break;
            case "Grant":
                $searcher = $xmlDoc->getElementsByTagName("Grant");
                $module->showNode($searcher,$nodeArray,$filters);
                $returnArray[$destinationField] = $nodeArray['GrantID']['value']."/".$nodeArray['Agency']['value']."/".$nodeArray['Country']['value'];
                break;
            case "DateCompleted":
            case "DateRevised":
            case "PubDate":
            case "ArticleDate":
            case "PubMedPubDate":
                $searcher = $xmlDoc->getElementsByTagName($xmlParamaters[0]);
                $module->showNode($searcher,$nodeArray,$filters);
                $returnArray[$destinationField] = $module->parseDateArrayForREDCapField($destFieldEnum, $nodeArray,$xmlParamaters[0]);
                break;
            default:
                $searcher = $xmlDoc->getElementsByTagName($xmlParamaters[0])->item(0);
                $module->showNode($searcher,$nodeArray,$filters);
                $returnArray[$destinationField] = $nodeArray[$xmlParamaters[0]]['value'].(isset($nodeArray[$xmlParamaters[0]]['tags']) && !empty($nodeArray[$xmlParamaters[0]]['tags']) ? " (".$nodeArray[$xmlParamaters[0]]['tags'][$xmlParamaters[1]].")" : "");
                break;
        }
    }*/
    echo json_encode($returnArray);
}