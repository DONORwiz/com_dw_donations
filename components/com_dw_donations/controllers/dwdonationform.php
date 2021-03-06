<?php

/**
 * @version     1.0.0
 * @package     com_dw_donations
 * @copyright   Copyright (C) 2014. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 * @author      Charalampos Kaklamanos <dev.yesinternet@gmail.com> - http://www.yesinternet.gr
 */
// No direct access
defined('_JEXEC') or die;

require_once JPATH_ROOT . '/components/com_dw_donations/controller.php';

/**
 * Donation controller class.
 */
class Dw_donationsControllerDwDonationForm extends Dw_donationsController {

	public function donate(){
		
		$result=array();
		
		$jinput = JFactory::getApplication()->input;
		$form_data = JFactory::getApplication()->input->get('jform', array(), 'array');
		
		$app = JFactory::getApplication();
        $model = $this->getModel('DwDonationForm', 'Dw_donationsModel');
		
		$params=array('controller'=>$this,'model'=>$model,'jinput'=>$jinput);
		
		$validation=self::fn_initial_validation($form_data);
		if($validation!==true){
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>$validation),'params'=>$params));
			return false;
		}
			
		$donation=$this->form_validate();
		if($donation===false){
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST')),'params'=>$params));
			return false;
		}

		$request =  VIVA_URL.'/api/orders';
		
		$beneficiary = CFactory::getUser($form_data['beneficiary_id']);
		
		// Your merchant ID and API Key can be found in the 'Security' settings on your profile.
		$MerchantId = $beneficiary->getInfo('FIELD_NGO_VIVA_MERCHANTID');
		$APIKey = $beneficiary->getInfo('FIELD_NGO_VIVA_APIKEY');
		$SourceCode = $beneficiary->getInfo('FIELD_NGO_VIVA_SOURCECODE');
		
		//Set the Payment Amount
		$Amount = intval( $donation['amount'] ) * 100;	// Amount in cents
		
		//Set some optional parameters
		$AllowRecurring = 'false'; // This flag will prompt the customer to accept recurring payments in tbe future.
		$RequestLang = 'el-GR'; //This will display the payment page in English (default language is Greek)
		$PaymentTimeOut = intval( JComponentHelper::getParams('com_dw_donations')->get('payment_timeout') ) * 24 * 60 * 60;
		
		$postargs = 'Amount='.urlencode($Amount);
		$postargs .= '&AllowRecurring='.$AllowRecurring;
		$postargs .= '&RequestLang='.$RequestLang;
		$postargs .= '&SourceCode='.$SourceCode;
		//$postargs .= '&FullName='.$donation['fname'].' '.$donation['lname'];
		$postargs .= '&Email='.$donation['email'];
		$postargs .= ($donation['payment_method']=='C')?'':'&PaymentTimeOut='.$PaymentTimeOut;
		$postargs .= '&DisableIVR=true';
		
		// Get the curl session object
		$session = curl_init($request);
		
		
		// Set the POST options.
		curl_setopt($session, CURLOPT_POST, true);
		curl_setopt($session, CURLOPT_POSTFIELDS, $postargs);
		curl_setopt($session, CURLOPT_HEADER, false);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($session, CURLOPT_USERPWD, $MerchantId.':'.$APIKey);
		
		// Do the POST and then close the session
		$response = curl_exec($session);
		curl_close($session);
		
		// Parse the JSON response
		try {
			if(is_object(json_decode($response))){
				$resultObj=json_decode($response);	
			}else{
				throw new Exception("Result is not a json object");
			}
		} catch( Exception $e ) {
			JLog::add($e->getMessage(), JLog::WARNING, 'donate');
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST')),'params'=>$params));
			return false;
		}
		
		if(!isset($resultObj->ErrorCode)){
			JLog::add($response, JLog::WARNING, 'donate');
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST')),'params'=>$params));
			return false;
		}
		
		if ($resultObj->ErrorCode===0){	//success when ErrorCode = 0
			$orderId = $resultObj->OrderCode;
			
			$donation['order_code']=$orderId;
			// Attempt to save the data.
       		$return = $model->save($donation);
			if ($return === false) {
				// Save the data in the session.
				$app->setUserState('com_dw_donations.edit.donation.data', $donation);
				$msg=JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST');
				echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>$msg),'params'=>$params));
				return false;
			}			
			
			// Check in the profile.
			if ($return) {
				$model->checkin($return);
			}
			// Save donation data in the session
			$app->setUserState('com_dw_donations.payment.data', $donation);
			$app->setUserState('com_dw_donations.returnfromviva', false);
			$app->setUserState('com_dw_donations.donation.data', $donation);
			// Clear the profile id from the session.
			$app->setUserState('com_dw_donations.edit.donation.id', null);
			// Flush the data from the session.
	        $app->setUserState('com_dw_donations.edit.donation.data', null);
			
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('orderId'=>$orderId,'params'=>$params));
			return false;
		}else{
			JLog::add($resultObj->ErrorText, JLog::WARNING, 'donate');
			echo JLayoutHelper::render('dwdonationform.donation_redirect',array('error'=>array('error_text'=>JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST')),'params'=>$params));
			return false;
		}
	}
	
	private function form_validate(){
		JSession::checkToken() or jexit(JText::_('JINVALID_TOKEN'));

        // Initialise variables.
        $app = JFactory::getApplication();
        $model = $this->getModel('DwDonationForm', 'Dw_donationsModel');

        // Get the user data.
        $data = JFactory::getApplication()->input->get('jform', array(), 'array');

        // Validate the posted data.
        $form = $model->getForm();
        if (!$form) {
            JError::raiseError(500, $model->getError());
            return false;
        }

        // Validate the posted data.
        $data = $model->validate($form, $data);
		
		// Check for errors.
        if ($data === false) {
            // Get the validation messages.
            $errors = $model->getErrors();

            // Push up to three validation messages out to the user.
            for ($i = 0, $n = count($errors); $i < $n && $i < 3; $i++) {
                if ($errors[$i] instanceof Exception) {
                    $app->enqueueMessage($errors[$i]->getMessage(), 'warning');
                } else {
                    $app->enqueueMessage($errors[$i], 'warning');
                }
            }

            $input = $app->input;
            $jform = $input->get('jform', array(), 'ARRAY');

            // Save the data in the session.
            $app->setUserState('com_dw_donations.edit.donation.data', $jform, array());

            // Redirect back to the edit screen.
            //$id = (int) $app->getUserState('com_moneydonations.edit.moneydonation.id');
            $this->setRedirect(JRoute::_('index.php?option=com_dw_donations&view=dwdonationform', false));
            return false;
        }else{
			return $data;
		}
	}
	
	private function fn_initial_validation($data)
	{
		if(!JSession::checkToken()){
			return JText::_('JINVALID_TOKEN');
		}
		if(!self::fn_check_donation_exists($data)){
			return JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST');
		}
		
		if(!self::fn_validate_donor($data)){
			return JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST');
		}
		if(!self::fn_validate_beneficiary($data)){
			return JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST');
		}
		if(!self::fn_check_donations_amount($data)){
			return JText::_('JERROR_LAYOUT_ERROR_HAS_OCCURRED_WHILE_PROCESSING_YOUR_REQUEST');
		}
		if(!JSession::checkToken()){
			return JText::_('JINVALID_TOKEN');
		}
		return true;
	}
	
	private function fn_check_donation_exists($data)
	{
		if($data['id']!=0){
			return false;
		}
		return true;
	}
	
	private function fn_validate_donor($data)
	{		
		$donor_id=$data['donor_id'];
		$created_by=$data['created_by'];
		
		$user_id = JFactory::getUser()->get('id');
		
		if($donor_id!=$created_by){
			return false;	
		}
		
		if($donor_id!=$user_id){
			return false;
		}
		
		$donorwizUser=new DonorwizUser($donor_id);
		$donor_is_beneficiary = $donorwizUser-> isBeneficiary('com_donorwiz');
		
		if($donor_is_beneficiary)
		{
			return false;
		}
		
		return true;
	}
	
	private function fn_validate_beneficiary($data)
	{		
		$beneficiary_id=$data['beneficiary_id'];
		
		$table   = JUser::getTable();
		
		$beneficiary_exists=$table->load( $beneficiary_id );
		
		if($beneficiary_exists){
			$donorwizUser=new DonorwizUser($beneficiary_id);
			$beneficiary_is_beneficiary = $donorwizUser-> isBeneficiary('com_dw_donations');
		}
		
		if(!$beneficiary_exists || !$beneficiary_is_beneficiary)
		{
			return false;
		}
		
		return true;
	}
	
	private function fn_check_donations_amount($data)
	{
		if($data['amount']>999 || $data['amount']<1){
			return false;
		}
		return true;
	}

}
