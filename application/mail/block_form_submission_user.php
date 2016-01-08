<?php 
defined('C5_EXECUTE') or die("Access Denied.");
$submittedData='';
foreach($questionAnswerPairs as $questionAnswerPair){
	$submittedData .= $questionAnswerPair['question']."\r\n".$questionAnswerPair['answerDisplay']."\r\n"."\r\n";
} 
$formDisplayUrl=URL::to($url_path) . '?qsid='.$questionSetId;
//echo $formDisplayUrl;
$body = t("
You have submitted the following message from the form \"%s\" through our website.

-----

%s

-----

Thank you.

", $formName, $submittedData);