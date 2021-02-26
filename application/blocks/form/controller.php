<?php

namespace Application\Block\Form;

use Concrete\Block\Form\Controller as CoreController;
use Concrete\Core\Block\BlockController;
use Concrete\Core\Entity\File\Version;
use Concrete\Core\User\User;
use Concrete\Core\Validator\String\EmailValidator;
use Config;
use Core;
use Database;
use Events;
use Exception;
use File;
use FileImporter;
use FileSet;
use Page;
use UserInfo;

class Controller extends CoreController
{
    /**
     * Users submits the completed survey.
     *
     * @param int $bID
     */
    public function action_submit_form($bID = false)
    {
        if ($this->bID != $bID) {
            return false;
        }

        $ip = Core::make('helper/validation/ip');
        $this->view();

        if ($ip->isBlacklisted()) {
            $this->set('invalidIP', $ip->getErrorMessage());

            return;
        }

        $txt = Core::make('helper/text');
        $db = Database::connection();

        //question set id
        $qsID = (int) ($_POST['qsID']);
        if ($qsID == 0) {
            throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have a question set id)."));
        }
        $errors = [];

        $token = Core::make('token');
        if (!$token->validate('form_block_submit_qs_' . $qsID)) {
            $errors[] = $token->getErrorMessage();
        }

        //get all questions for this question set
        $rows = $db->GetArray("SELECT * FROM {$this->btQuestionsTablename} WHERE questionSetId=? AND bID=? order by position asc, msqID", [$qsID, (int) ($this->bID)]);

        if (!count($rows)) {
            throw new Exception(t("Oops, something is wrong with the form you posted (it doesn't have any questions)."));
        }

        $errorDetails = [];

        // check captcha if activated
        if ($this->displayCaptcha) {
            $captcha = Core::make('helper/validation/captcha');
            if (!$captcha->check()) {
                $errors['captcha'] = t('Incorrect captcha code');
                $_REQUEST['ccmCaptchaCode'] = '';
            }
        }
        //checked required fields
        foreach ($rows as $row) {
            if ($row['inputType'] == 'datetime') {
                if (!isset($datetime)) {
                    $datetime = Core::make('helper/form/date_time');
                }
                $translated = $datetime->translate('Question' . $row['msqID']);
                if ($translated) {
                    $_POST['Question' . $row['msqID']] = $translated;
                }
            }
            if ((int) ($row['required']) == 1) {
                $notCompleted = 0;
                if ($row['inputType'] == 'email') {
                    if (!isset($emailValidator)) {
                        $emailValidator = $this->app->make(EmailValidator::class);
                    }
                    $e = $this->app->make('error');
                    if (!$emailValidator->isValid($_POST['Question' . $row['msqID']], $e)) {
                        $errors['emails'] = $e->toText();
                        $errorDetails[$row['msqID']]['emails'] = $errors['emails'];
                    }
                }
                if ($row['inputType'] == 'checkboxlist') {
                    $answerFound = 0;
                    foreach ($_POST as $key => $val) {
                        if (strstr($key, 'Question' . $row['msqID'] . '_') && strlen($val)) {
                            $answerFound = 1;
                        }
                    }
                    if (!$answerFound) {
                        $notCompleted = 1;
                    }
                } elseif ($row['inputType'] == 'fileupload') {
                    if (!isset($_FILES['Question' . $row['msqID']]) || !is_uploaded_file($_FILES['Question' . $row['msqID']]['tmp_name'])) {
                        $notCompleted = 1;
                    }
                } elseif (!strlen(trim($_POST['Question' . $row['msqID']]))) {
                    $notCompleted = 1;
                }
                if ($notCompleted) {
                    $errors['CompleteRequired'] = t('Complete required fields *');
                    $errorDetails[$row['msqID']]['CompleteRequired'] = $errors['CompleteRequired'];
                }
            }
        }

        //try importing the file if everything else went ok
        $tmpFileIds = [];
        if (!count($errors)) {
            foreach ($rows as $row) {
                if ($row['inputType'] != 'fileupload') {
                    continue;
                }
                $questionName = 'Question' . $row['msqID'];
                if (!(int) ($row['required']) &&
                    (
                        !isset($_FILES[$questionName]['tmp_name']) || !is_uploaded_file($_FILES[$questionName]['tmp_name'])
                    )
                ) {
                    continue;
                }
                $fi = new FileImporter();
                $resp = $fi->import($_FILES[$questionName]['tmp_name'], $_FILES[$questionName]['name']);
                if (!($resp instanceof Version)) {
                    switch ($resp) {
                        case FileImporter::E_FILE_INVALID_EXTENSION:
                            $errors['fileupload'] = t('Invalid file extension.');
                            $errorDetails[$row['msqID']]['fileupload'] = $errors['fileupload'];
                            break;
                        case FileImporter::E_FILE_INVALID:
                            $errors['fileupload'] = t('Invalid file.');
                            $errorDetails[$row['msqID']]['fileupload'] = $errors['fileupload'];
                            break;
                    }
                } else {
                    $tmpFileIds[(int) ($row['msqID'])] = $resp->getFileID();
                    if ((int) ($this->addFilesToSet)) {
                        $fs = new FileSet();
                        $fs = $fs->getByID($this->addFilesToSet);
                        if ($fs->getFileSetID()) {
                            $fs->addFileToSet($resp);
                        }
                    }
                }
            }
        }

        if (count($errors)) {
            $this->set('formResponse', t('Please correct the following errors:'));
            $this->set('errors', $errors);
            $this->set('errorDetails', $errorDetails);
        } else { //no form errors
            //save main survey record
            $u = $this->app->make(User::class);
            $uID = 0;
            if ($u->isRegistered()) {
                $uID = $u->getUserID();
            }
            $q = "insert into {$this->btAnswerSetTablename} (questionSetId, uID) values (?,?)";
            $db->query($q, [$qsID, $uID]);
            $answerSetID = $db->Insert_ID();
            $this->lastAnswerSetId = $answerSetID;

            $questionAnswerPairs = [];

            if (Config::get('concrete.email.form_block.address') && strstr(Config::get('concrete.email.form_block.address'), '@')) {
                $formFormEmailAddress = Config::get('concrete.email.form_block.address');
            } else {
                $adminUserInfo = UserInfo::getByID(USER_SUPER_ID);
                $formFormEmailAddress = $adminUserInfo->getUserEmail();
            }
            $replyToEmailAddress = $formFormEmailAddress;
            //loop through each question and get the answers
            $sendConfirmationEmail = false;
            foreach ($rows as $row) {
                //save each answer
                $answerDisplay = '';
                if ($row['inputType'] == 'checkboxlist') {
                    $answer = [];
                    $answerLong = '';
                    $keys = array_keys($_POST);
                    foreach ($keys as $key) {
                        if (strpos($key, 'Question' . $row['msqID'] . '_') === 0) {
                            $answer[] = $txt->sanitize($_POST[$key]);
                        }
                    }
                } elseif ($row['inputType'] == 'text') {
                    $answerLong = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    $answer = '';
                } elseif ($row['inputType'] == 'fileupload') {
                    $answerLong = '';
                    $answer = (int) ($tmpFileIds[(int) ($row['msqID'])]);
                    if ($answer > 0) {
                        $answerDisplay = File::getByID($answer)->getVersion()->getDownloadURL();
                    } else {
                        $answerDisplay = t('No file specified');
                    }
                } elseif ($row['inputType'] == 'datetime') {
                    $formPage = $this->getCollectionObject();
                    $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    if ($formPage) {
                        $site = $formPage->getSite();
                        $timezone = $site->getTimezone();
                        $date = $this->app->make('date');
                        $answerDisplay = $date->formatDateTime($txt->sanitize($_POST['Question' . $row['msqID']]), false, false, $timezone);
                    } else {
                        $answerDisplay = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    }
                } elseif ($row['inputType'] == 'url') {
                    $answerLong = '';
                    $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                } elseif ($row['inputType'] == 'email') {
                    $answerLong = '';
                    $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                    if (!empty($row['options'])) {
                        $settings = unserialize($row['options']);
                        if (is_array($settings) && array_key_exists('send_notification_from', $settings) && $settings['send_notification_from'] == 1) {
                            $email = $txt->email($answer);
                            if (!empty($email)) {
                                $replyToEmailAddress = $email;
                                $sendConfirmationEmail = true;
                            }
                        }
                    }
                } elseif ($row['inputType'] == 'telephone') {
                    $answerLong = '';
                    $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                } else {
                    $answerLong = '';
                    $answer = $txt->sanitize($_POST['Question' . $row['msqID']]);
                }

                if (is_array($answer)) {
                    $answer = implode(',', $answer);
                }

                $questionAnswerPairs[$row['msqID']]['question'] = $row['question'];
                $questionAnswerPairs[$row['msqID']]['answer'] = $txt->sanitize($answer . $answerLong);
                $questionAnswerPairs[$row['msqID']]['answerDisplay'] = strlen($answerDisplay) ? $answerDisplay : $questionAnswerPairs[$row['msqID']]['answer'];

                $v = [$row['msqID'], $answerSetID, $answer, $answerLong];
                $q = "insert into {$this->btAnswersTablename} (msqID,asID,answer,answerLong) values (?,?,?,?)";
                $db->query($q, $v);
            }
            $foundSpam = false;

            $submittedData = '';
            foreach ($questionAnswerPairs as $questionAnswerPair) {
                $submittedData .= $questionAnswerPair['question'] . "\r\n" . $questionAnswerPair['answer'] . "\r\n" . "\r\n";
            }
            $antispam = Core::make('helper/validation/antispam');
            if (!$antispam->check($submittedData, 'form_block')) {
                // found to be spam. We remove it
                $foundSpam = true;
                $q = "delete from {$this->btAnswerSetTablename} where asID = ?";
                $v = [$this->lastAnswerSetId];
                $db->Execute($q, $v);
                $db->Execute("delete from {$this->btAnswersTablename} where asID = ?", [$this->lastAnswerSetId]);
            }

            if ((int) ($this->notifyMeOnSubmission) > 0 && !$foundSpam) {
                if (Config::get('concrete.email.form_block.address') && strstr(Config::get('concrete.email.form_block.address'), '@')) {
                    $formFormEmailAddress = Config::get('concrete.email.form_block.address');
                } else {
                    $adminUserInfo = UserInfo::getByID(USER_SUPER_ID);
                    $formFormEmailAddress = $adminUserInfo->getUserEmail();
                }

                $mh = Core::make('helper/mail');
                $mh->to($this->recipientEmail);
                $mh->from($formFormEmailAddress);
                $mh->replyto($replyToEmailAddress);
                $mh->addParameter('formName', $this->surveyName);
                $mh->addParameter('questionSetId', $this->questionSetId);
                $mh->addParameter('questionAnswerPairs', $questionAnswerPairs);
                $mh->load('block_form_submission');
                if (empty($mh->getSubject())) {
                    $mh->setSubject(t('%s Form Submission', $this->surveyName));
                }
                //echo $mh->body.'<br>';
                @$mh->sendMail();
                if ($sendConfirmationEmail) {
                    $mh = null;
                    $mh = Core::make('helper/mail');
                    $mh->from($formFormEmailAddress);
                    $mh->to($replyToEmailAddress);
                    $mh->replyto($this->recipientEmail);
                    $mh->addParameter('formName', $this->surveyName);
                    $mh->addParameter('questionSetId', $this->questionSetId);
                    $mh->addParameter('questionAnswerPairs', $questionAnswerPairs);
                    $mh->load('block_form_submission_user');
                    if (empty($mh->getSubject())) {
                        $mh->setSubject(t('%s Form Submission', $this->surveyName));
                    }
                    @$mh->sendMail();
                }
            }

            //launch form submission event with dispatch method
            $formEventData = [];
            $formEventData['bID'] = (int) ($this->bID);
            $formEventData['questionSetID'] = $this->questionSetId;
            $formEventData['replyToEmailAddress'] = $replyToEmailAddress;
            $formEventData['formFormEmailAddress'] = $formFormEmailAddress;
            $formEventData['questionAnswerPairs'] = $questionAnswerPairs;
            $event = new \Symfony\Component\EventDispatcher\GenericEvent();
            $event->setArgument('formData', $formEventData);
            Events::dispatch('on_form_submission', $event);

            if (!$this->noSubmitFormRedirect) {
                $targetPage = null;
                if ($this->redirectCID > 0) {
                    $pg = Page::getByID($this->redirectCID);
                    if (is_object($pg) && $pg->cID) {
                        $targetPage = $pg;
                    }
                }
                if (is_object($targetPage)) {
                    $response = \Redirect::page($targetPage);
                } else {
                    $response = \Redirect::page(Page::getCurrentPage());
                    $url = $response->getTargetUrl() . '?surveySuccess=1&qsid=' . $this->questionSetId . '#formblock' . $this->bID;
                    $response->setTargetUrl($url);
                }
                $response->send();
                exit;
            }
        }
    }
}
