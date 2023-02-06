<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Benefit extends CI_Controller {

	function __construct(){
		parent::__construct();
		
		$this->load->model('Benefit_model');
		$this->load->model('Api_model');
		$this->load->model('Exchange_model');
		$this->load->library('Temp_page');
		$this->load->helper('common');
		$this->data = array();
		

		$this->Ymd	= date("Y-m-d");
		$this->YmdHis	=	date("Y-m-d H:i:s");
		
		$this->debug_start_date	= date("Y-m-d", strtotime($this->Ymd." -1 day"));
		$this->debug_end_date		= date("Y-m-d");

		$this->deadline_start_Ymd	= date("Ymd", strtotime($this->Ymd." -1 day"));//매출기준일
		$this->deadline_end_Ymd		= date("Ymd");

		$this->deadline_Ym	= date("Ym", strtotime($this->Ymd." -1 month"));//매출기준일


//		$this->Ymd	= '2021-02-02';
//		$this->YmdHis	=	'2021-02-02 00:30:00';
//		
//		$this->debug_start_date	= date("Y-m-d", strtotime($this->Ymd." -1 day"));
//		$this->debug_end_date		= $this->Ymd;
//
//		$this->deadline_start_Ymd	= date("Ymd", strtotime($this->Ymd." -1 day"));//매출기준일
//		$this->deadline_end_Ymd		= '20210202';
//
//		$this->deadline_Ym	= date("Ym", strtotime($this->Ymd." -1 month"));//매출기준일


		$this->matching_level			= 3;//추천후원매칭 레벨
	}
   	
	public function init(){
		log_deadline("마감", 'start');
		$this->setReferralBonus();//추천수당
		$this->setSupport();		//후원수당,추천후원매칭
		$this->delSupportBonus();//후원수당 좌우직추천 확인후 보너스 삭제
		$this->subtractBonus();	//일극점차감
		$this->setDailyMiningBonus();//마이닝 데일리
		$this->setDailyGlobalBonus();//글로벌 보너스
		$this->givePackageMbm();//프로모션 패키지 20%씩 한달마다 주는
		//직급수당		
		if(date('d') ==10) {
			$this->gradeBonus();
		}
		$this->delUserBonus();//지정된 회원 후원수당 및 후원추천매칭 삭제
		log_deadline("마감", 'End');
	}

	public function setSupport(){
		log_deadline("후원보너스", 'start');
		$benefit_rate =0.1;
		$receive_bonus=0;
		$support_bonus=0;
		$rightCarriedTotalBonus = 0;
		$leftCarriedTotalBonus = 0;
		$rightSupport_bonus = 0;
		$leftSupport_bonus = 0;
		$big_sales_position='';
		$big_sales_amount=0;
		$carrier_bonus = 0;
		$i=1;
		
		//1.후원인코드가있는경우
		$support = $this->Benefit_model->validSupport();
		$list = $support['page_list'] ;
		
		foreach($list as $val){
			$user_id = $val->cmm_id;
			$user_idx = $val->cmm_seq;
			if($user_id !='captain') {
				//패키지구매확인(몸값확인필요)
				$package	= $this->Benefit_model->getPackagePurchase($val->cmm_id);
				if($package['res']->pv_amount){
					$matching_level = $this->matching_level;
					$getSupport			= $this->Benefit_model->getAllSupportPosition($val->cmm_id);
					
					$input['table']		= 'TB_SPONSOR_TOTAL_SALES';
					$input['user_id']	= $user_id;
					$input['end_ymd']	= $this->Ymd;
					$result_view = $this->Benefit_model->getSponsorTotalBonus($input);
					
					$rightTotalBonus	= $result_view['page_view']->right_amount;
					$leftTotalBonus		= $result_view['page_view']->left_amount;
					
					if($result_view['total_cnt'] > 0) {
						if($rightTotalBonus > 0 || $leftTotalBonus > 0){
							$leftSupport = $this->Benefit_model->getSupportBouns($getSupport->LEFT_ID,$this->deadline_start_Ymd,$this->deadline_end_Ymd);
							$leftSupport_bonus = $leftSupport->support_bouns;
							$rightSupport = $this->Benefit_model->getSupportBouns($getSupport->RIGHT_ID,$this->deadline_start_Ymd,$this->deadline_end_Ymd);
							$rightSupport_bonus = $rightSupport->support_bouns;
							if($leftSupport_bonus > 0 || $rightSupport_bonus > 0){

								$input['table']		= 'TB_SPONSOR_CARRIED_SALES';
								$input['user_id']	= $user_id;
								$result_view = $this->Benefit_model->getSponsorCarriedBonus($input);
								$carriedRightTotalBonus	= $result_view['page_view']->right_amount;
								$carriedLeftTotalBonus	= $result_view['page_view']->left_amount;
								
								$rightCarriedTotalBonus = $carriedRightTotalBonus+$rightSupport_bonus;
								$leftCarriedTotalBonus	= $carriedLeftTotalBonus+$leftSupport_bonus;
								
								if($rightCarriedTotalBonus > 0 && $leftCarriedTotalBonus > 0){
									if($rightCarriedTotalBonus > $leftCarriedTotalBonus){
										$support_bonus = $leftCarriedTotalBonus*$benefit_rate;
										$big_sales_position = 'R';
										$big_sales_amount = $rightCarriedTotalBonus-$leftCarriedTotalBonus;
									}else{
										$support_bonus = $rightCarriedTotalBonus*$benefit_rate;
										$big_sales_position = 'L';
										$big_sales_amount = $leftCarriedTotalBonus-$rightCarriedTotalBonus;
									}
									
									$receive_bonus =	$support_bonus;
									$carrier_bonus =	$big_sales_amount;
									$i++;

									if($val->support_cnt > 1 ){
										/*
											skm 2022.01.14
											후원 계보도 좌우 매출친 직추천자 수 추가
										 */
										#후원 계보도 좌우 직추천자 수
										$referral_support_cnt = $this->Benefit_model->getReferralSupportBouns($user_id);
										if($referral_support_cnt < 2){
											log_deadline("후원수당 못받음",":user_id:".$user_id.":amount:".(float)$receive_bonus.":좌.우 직추천수:".$referral_support_cnt);
										}else{
											//4.후원수당지급
											$this->setSupportBonus($user_id,$user_idx,$receive_bonus);
											//5.추천 후원 매칭
											$this->setMatchingBonus($user_id,$receive_bonus,$matching_level);
										}
									}

								}else{
									if($rightCarriedTotalBonus > $leftCarriedTotalBonus){
										$support_bonus = $leftCarriedTotalBonus*$benefit_rate;
										$big_sales_position = 'R';
										$big_sales_amount = $rightCarriedTotalBonus;
									}else{
										$support_bonus = $rightCarriedTotalBonus*$benefit_rate;
										$big_sales_position = 'L';
										$big_sales_amount = $leftCarriedTotalBonus;
									}

									$carrier_bonus =	$big_sales_amount;
									//echo "후원보너스 못받음<br>";
								}

								$debug		= $this->Benefit_model->spSponsorCarriedSalesDebug($user_id,$this->debug_start_date,$this->debug_end_date);
								$res_str	= $user_id."|마감매출일:".$this->debug_end_date."|".$debug->return_res."|".$debug->return_str."|이월실적:".$carrier_bonus;
								
								log_deadline("후원보너스", $res_str);
								
								/*
									실적 REFRESH
								*/

								$input['table']		= 'TB_SPONSOR_CARRIED_SALES';
								$input['user_id']	= $user_id;
								if($big_sales_position == 'L'){
									$db_input['left_amount']	= $carrier_bonus;
									$db_input['right_amount']	= 0;
								}else{
									$db_input['left_amount']	= 0;
									$db_input['right_amount']	= $carrier_bonus;
								}

								$db_input['regist_date']	= $this->Ymd;
								$db_input['sales_date']		= $this->debug_start_date;
								$this->Benefit_model->updateSponsorCarriedBonus($db_input,$input);
							}else{
								echo "-";
							}
						}
						echo "###############################<BR>";
					}
				}
			}
		}
		log_deadline("setSupport", 'End');
	}
	
	public function setMatchingBonus($user_id,$receive_bonus,$matching_level){
		log_deadline("추천후원매칭보너스", 'start');
		$i =1;
		$five_uplevel_payment ='N';
		$matching_input['user_id']		= $user_id;
		$matching_input['downlevel']	= $matching_level;
		$matching_input['order_by']		= "downlevel";
		$matching = $this->Benefit_model->getReferraUpLevel($matching_input);
		$matching_list = $matching['page_list'];
		
		$user_referral_cnt = $this->Benefit_model->getMatchReferralBouns($user_id); //후원보너스받은 회원의 좌.우 직추천
		
		if($user_referral_cnt < 2){
			log_deadline("후원추천매칭 본인ID(못받음)",":user_id:".$user_id.":amount:".(float)$receive_bonus.":좌.우 직추천수:".$user_referral_cnt);
			return ;
		}
	
		if($matching['total_cnt'] > 0){
			foreach($matching_list as $val){
				switch($val->downlevel){
					case 1:	$matching_benefit_rate = 0.3;break;
					case 2:	$matching_benefit_rate = 0.2;break;
					case 3:	$matching_benefit_rate = 0.1;break;
					default : $matching_benefit_rate = 0.1;break;
				}
				
				$amount = $receive_bonus*$matching_benefit_rate;
				
				$field = array('cmm_id' => $val->id,'cmm_remove_yn' => 'N', 'cmm_matching_yn' => 'Y');
				$member = $this->Benefit_model->getUserByParm($field);//M2이상패키지구매회원

				$referral = $this->Benefit_model->validReferral($val->id);
				$cnt = $this->Benefit_model->getMatchReferralBouns($val->id);//좌.우 직추천 확인
				/*
					M2이상패키지구매회원일경우
				*/
				if($member['cnt'] > 0 ){
					if($referral['total_cnt'] >= 2 ){
						/*
							좌.우 직추천이 있을경우	
						*/
						if($cnt == 2){
							###### pv,소실적기준추가 #####
							switch($val->downlevel){
								case 1:	$matching_amount = 12300;break;
								case 2:	$matching_amount = 17220;break;
								case 3:	$matching_amount = 24600;break;
								default : $matching_amount = 12300;break;
							}

							$pkSponAmt = $this->Benefit_model->getPkSponAmt($val->id);
							$sponsor_amt	=	$pkSponAmt->sponsor_amt;
							$pv_amont			=	$pkSponAmt->pv_amont;

							if(($sponsor_amt >= $matching_amount) || ($pv_amont >= $matching_amount)){
								$tid		= orderNo();
								$input['table'] = "coin_exchange";
								$exchange_db_input['user_id']      = $val->id;
								$exchange_db_input['user_idx']     = $val->cmm_seq;
								$exchange_db_input['from_id']      = $user_id;
								$exchange_db_input['cecg_gubun']   = 1;
								$exchange_db_input['cecg_action']  = 113;
								$exchange_db_input['cecg_amount']  = $amount;
								$exchange_db_input['cecg_tid']     = $tid;
								$exchange_db_input['cecg_paied']    = 'Y';
								$exchange_db_input['cecg_regdt']    = $this->YmdHis;
								$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;
								
								$result = $this->Benefit_model->exchange($exchange_db_input,$input);

								if($result ==true) {
									$res =  $this->Benefit_model->getbalance('1',$val->cmm_seq);
									$db_input['total_amount'] =	$res->total_amount;
									$db_input['user_id']      =	$val->id;
									$db_input['cecg_gubun']   =	1;
									$this->Benefit_model->accountAmount($db_input,$input);
								}
							}
						}
					}

					$i++;
					log_deadline("후원추천매칭 회원결과","idx:".$val->cmm_seq.":user_id:".$val->id.":amount:".(float)$amount.":좌.우 직추천수:".$cnt);
				}
			}
		}
		log_deadline("추천후원매칭보너스", 'End');
	}

	public function setSupportBonus($user_id,$user_idx,$receive_bonus){
		$tid		= orderNo();
		$input['table'] = "coin_exchange";
		$exchange_db_input['user_id']      = $user_id;
		$exchange_db_input['user_idx']     = $user_idx;
		$exchange_db_input['cecg_gubun']   = 1;
		$exchange_db_input['cecg_action']  = 112;
		$exchange_db_input['cecg_amount']  = $receive_bonus;
		$exchange_db_input['cecg_tid']     = $tid;
		$exchange_db_input['cecg_paied']    = 'Y';
		$exchange_db_input['cecg_regdt']    = $this->YmdHis;
		$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;

		$result = $this->Benefit_model->exchange($exchange_db_input,$input);
		if($result ==false) {
			echo $result;
		}else{
		 $res =  $this->Benefit_model->getbalance('1',$user_idx);
		 $db_input['total_amount'] =	$res->total_amount;
		 $db_input['user_id']      =	$user_id;
		 $db_input['cecg_gubun']   =	1;
		 $this->Benefit_model->accountAmount($db_input,$input);
		}
	}

	/*
		추천보너스
	*/

	public function setReferralBonus(){
		log_deadline("추천보너스", 'start');
		$result = $this->Benefit_model->giveBouns($this->Ymd);
		log_deadline("추천보너스", 'End');
	}
	
	/*
	일극점차감
	*/
	public function subtractBonus(){
		log_deadline("일극점차감 ", 'start');
		$limit_bonus = 0;
		$refresh_bonus = 0;
		$limit_value = 0;
		
		$users = $this->Benefit_model->receiveBounsUser($this->Ymd);
		if($users['total_cnt'] > 0 ){
			$i=1;
			$list = $users['page_list'];
			foreach($list as $val){
				$package	= $this->Benefit_model->getPackagePurchase($val->user_id);
				$daily_total = $this->Benefit_model->totalBenefit($val->user_id,$this->Ymd);
				
				$daily_total_bonus = $daily_total['result']->total_amount;
				$limit_value = $package['res']->pv_amount;
				
				if($daily_total_bonus > $limit_value ) {
					$refresh_bonus = $daily_total_bonus-$limit_value;
					$tid		= orderNo();
					$input['table'] = "coin_exchange";
					$exchange_db_input['user_id']      = $val->user_id;
					$exchange_db_input['user_idx']     = $val->user_idx;
					$exchange_db_input['from_id']      = '';
					$exchange_db_input['cecg_gubun']   = 1;
					$exchange_db_input['cecg_action']  = 123;
					$exchange_db_input['cecg_amount']  = "-".$refresh_bonus;
					$exchange_db_input['cecg_tid']     = $tid;
					$exchange_db_input['cecg_paied']	 = 'Y';
					$exchange_db_input['cecg_regdt']   = $this->YmdHis;
					$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;
					$this->Benefit_model->exchange($exchange_db_input,$input);

					log_deadline("일극점차감 회원","user_id:".$val->user_id.":amount:".(float)$refresh_bonus.":하루총보너스:".$daily_total_bonus.":몸값:".$limit_value);
				}
			}
		}
		log_deadline("일극점차감 ", 'End');
	}
	/*
		토탈후원매출,등급업데이트
	*/
	public function getTotalSupport(){
		log_deadline("토탈후원매출 ", 'start');
		$TOTAL_AMOUNT = 0;
		$i=1;
		$users = $this->Benefit_model->users();
		$list = $users['page_list'] ;
			foreach($list as $val){
				$user_id = $val->cmm_id;
				$user_idx = $val->cmm_seq;
				$user_level = $val->cmm_grade;
				
				if($user_id !='captain'){
					
					$getSupport		= $this->Benefit_model->getAllSupportPosition($user_id);
					
					$leftSupport	= $this->Benefit_model->getDeadLineTotalSupportBouns($getSupport->LEFT_ID,$this->deadline_end_Ymd);
					$leftSupport_bonus = $leftSupport->support_bouns;
					$rightSupport = $this->Benefit_model->getDeadLineTotalSupportBouns($getSupport->RIGHT_ID,$this->deadline_end_Ymd);
					$rightSupport_bonus = $rightSupport->support_bouns;
					
					$input['table']		= 'TB_SPONSOR_TOTAL_SALES';
					$input['user_id']	= $user_id;
					
					$sponsor_db_input['user_id']				= $user_id;
					$sponsor_db_input['left_amount']		= $leftSupport_bonus;
					$sponsor_db_input['right_amount']		= $rightSupport_bonus;
					$sponsor_db_input['close_datetime']	=	$this->YmdHis;
					$sponsor_db_input['sales_datetime']	=	$this->deadline_start_Ymd;
					
					$input['type'] ='update';
					$this->Benefit_model->sponsorDeadline($sponsor_db_input,$input);

					if($leftSupport_bonus > 0 && $rightSupport_bonus > 0){
						if($leftSupport_bonus > $rightSupport_bonus) {
							$TOTAL_AMOUNT = $rightSupport_bonus;
						}else{
							$TOTAL_AMOUNT = $leftSupport_bonus;
						}

						switch($TOTAL_AMOUNT) {
							case $TOTAL_AMOUNT <  24600: $LEVEL = 'Master0';break;
							case $TOTAL_AMOUNT >= 24600 and $TOTAL_AMOUNT < 73800: $LEVEL = 'Master1';break;
							case $TOTAL_AMOUNT >= 73800 and $TOTAL_AMOUNT < 442800: $LEVEL = 'Master2';break;
							case $TOTAL_AMOUNT >= 442800 and $TOTAL_AMOUNT < 2656800: $LEVEL = 'Master3';break;
							case $TOTAL_AMOUNT >= 2656800 and $TOTAL_AMOUNT < 7970400: $LEVEL = 'Master4';break;
							case $TOTAL_AMOUNT >= 7970400 : $LEVEL = 'Master5';break;
							default:$LEVEL = 'Master0';break;
						}
						
						if(str_replace("Master","",$LEVEL) > str_replace("Master","",$user_level)){
							$input['table']	= 'coin_member_master';
							$input['user_id']				= $user_id;
							$db_input['cmm_grade']	= $LEVEL;
							$db_input['cmm_level_updt']	= date("Y-m-d H:i:s");
							$this->Benefit_model->userUpdate($db_input,$input);

							$res_str = $user_id.' 소실적 매출:'.$TOTAL_AMOUNT.'('.$user_level.'->'.$LEVEL.')';
							log_deadline("직급업그레이드", $res_str);
						}
					}
				}
			}
			log_deadline("토탈후원매출 ", 'End');
	}

	/*
		후원수당데이터삭제(직추천이 후원에 좌우각1명씩 없는회원)
	*/

	public function delSupportBonus()
	{
		//log_deadline("후원수당데이터삭제", 'START');

		$input['table'] = "coin_exchange";
		$input['deadlin_date'] = $this->Ymd;
		
		$support_bonus	=	$this->Benefit_model->getSupportBonusList($input);
		$list	=	$support_bonus['page_list'];
		$total_cnt=	$support_bonus['total_cnt'];
		$i=0;

		if($total_cnt >0){
			foreach ($list as $val) {
				$referral = $this->Benefit_model->validReferral($val->user_id);
				$cnt = $this->Benefit_model->getMatchReferralBouns($val->user_id);
				if($referral['total_cnt'] >= 2 ){
					if($cnt < 2) {
						$i++;
						/*
							coin_exchange cecg_action ='112' 데이터삭제
						*/
						$exchange_input['table'] = 'coin_exchange';
						$exchange_input['board_id'] = 'cecg';
						$exchange_input['idx']			= $val->cecg_idx;
						$this->Benefit_model->delete($exchange_input);
					}
				}else{
					 $i++;
					 $exchange_input['table'] = 'coin_exchange';
					 $exchange_input['board_id']	= 'cecg';
					 $exchange_input['idx']       = $val->cecg_idx;
					 $this->Benefit_model->delete($exchange_input);
				}
				
				//echo "후원수당삭제 회원결과","idx:".$val->cecg_idx.":user_id:".$val->user_id.":AMOUNT:".(float)$val->cecg_amount.":CNT:".$cnt.(($cnt==2)?"":"(삭제대상)")."<br>";
				log_deadline("후원수당삭제 회원결과","idx:".$val->cecg_idx.":user_id:".$val->user_id.":amount:".(float)$val->cecg_amount.":좌우추천인:".$cnt.(($cnt==2)?"":"(삭제대상)"));
			}
		}else{
			echo "no data";
			//log_deadline("후원수당삭제", 'no data');
		}
		log_deadline("후원수당삭제", '토탈회원:'.$total_cnt.'명:삭제회원:'.$i);
		echo '토탈회원:'.$total_cnt.'명:삭제회원:'.$i;
	}
	
	/*
		2021-01-14 pse
		패키지 구매한 회원 데일리 주는 로직
		1. 1/14일 까지 구매한 회원은 다음날 15일 부터 바로 데일리
		2. 1/15일 부터 구매한 회원은 15일 뒤에 주는 로직
		3. 데일리는 m1:20, m2:50,m3:180,m4:330,m5:580,m6:800
		ETH 와 모네로는 각각 0.01개씩
	*/
	public function setDailyMiningBonus(){
		log_deadline("마이닝JOBINGS ", 'start');
		//패키지 구매한 회원
		$package_list = $this->Benefit_model->getPackageList();
		$list	=	$package_list['page_list'];
		foreach ($list as $val) {
			if($val->cpi_gubun == 'Y'){
				$idx = $val->cpi_upgrade_idx;
				$before_package = $this->Benefit_model->getPackageInfo($idx);
				$package_date = $before_package->cpi_investdttm;
			}else{
				$package_date 		= $val->cpi_investdttm;
			}
			
			$package_date_ymd = substr($package_date,0,10);
			$package_code 		= $val->cpi_packagecode;
			$field = array('cmm_id' => $val->cpi_investor);
			$user = $this->Benefit_model->getUserByParm($field);
			$user_id 					= $val->cpi_investor;
			$user_idx 				= $user['info']->cmm_seq;
			/*
			회원 마이닝 토탈 포인트
			MBM,ETH,XMR
			*/
			
			/*
			패키지 별 갯수
			2021-01-20 pse
			패키지별 ETH,XMR 갯수 차등 지급하도록 변경
			2021-02-19 pse
			2021-02-22 월요일 마감 돌때 수량 감소
			2021-03-15 마감 수량 M1 10 -> 8 , M2 30 -> 22 변경
			2021-03-16 마감 수량 2021-03-22 부터 변경되도록 M1 10 -> 8 , M2 30 -> 22 변경
			2021-03-23 마감 BTT 추가
			2021-05-06 마감 ETH, XMR 수량 변경 
			*/
			
			if($this->Ymd >= '2021-09-20'){
				switch($package_code){
					case 'M1':$amount = 8;$coin_eth=0.00205;$coin_xmr=0.00315;$token=50;$coin_xch=0.00465;break;
					case 'M2':$amount = 22;$coin_eth=0.0041;$coin_xmr=0.0063;$token=100;$coin_xch=0.0093;break;
					case 'M3':$amount = 110;$coin_eth=0.0123;$coin_xmr=0.0189;$token=300;$coin_xch=0.0279;break;
					case 'M4':$amount = 200;$coin_eth=0.0205;$coin_xmr=0.0315;$token=500;$coin_xch=0.0465;break;
					case 'M5':$amount = 350;$coin_eth=0.0287;$coin_xmr=0.0441;$token=700;$coin_xch=0.0651;break;
					case 'M6':$amount = 640;$coin_eth=0.041;$coin_xmr=0.063;$token=1000;$coin_xch=0.093;break;
				}
			}else{
				switch($package_code){
					case 'M1':$amount = 8;$coin_eth=0.0031;$coin_xmr=0.00315;$token=50;$coin_xch=0.00465;break;
					case 'M2':$amount = 22;$coin_eth=0.0062;$coin_xmr=0.0063;$token=100;$coin_xch=0.0093;break;
					case 'M3':$amount = 110;$coin_eth=0.0186;$coin_xmr=0.0189;$token=300;$coin_xch=0.0279;break;
					case 'M4':$amount = 200;$coin_eth=0.031;$coin_xmr=0.0315;$token=500;$coin_xch=0.0465;break;
					case 'M5':$amount = 350;$coin_eth=0.0434;$coin_xmr=0.0441;$token=700;$coin_xch=0.0651;break;
					case 'M6':$amount = 640;$coin_eth=0.062;$coin_xmr=0.063;$token=1000;$coin_xch=0.093;break;
				}
			}
			
			
			#받아야될 마이닝 코인 갯수
			
			$mbm = ($amount*0.9)/2;
			$eth = $coin_eth;
			$xmr = $coin_xmr;
			$btt = $token;
			$xch = $coin_xch;
			
			/*
			 1/15일 전 패키지는 15일부터 데일리 
			 15일 이후로 구매한 패키지는 15일 뒤인 30일 이후로 데일리 적용
			 2021-01-15 pse
			 1-15일 에서 1-25일로 변경
			*/
			
			$date = date('Y-m-d');
			if($package_date_ymd >= '2021-09-20'){
				$day	=	30;
			}else{
				$day	=	15;
			}
			
			$package_ymd = date('Y-m-d',strtotime($package_date_ymd.' +'.$day.' days'));

			/*
			15일 이후 패키지에 15일 더한 날짜가 마감 도는날짜보다 같거나 크면 데일리 
			2021-01-15 pse
			25일 이후 패키지에 15일 더한 날짜가 마감 도는날짜보다 같거나 크면 데일리
			2021-09-17 pse
			9-20 이전 매출은 15일 뒤 JOBINGS 지급 이후미출은 30일뒤 JOBINGS 지급
			*/
			
			if($package_ymd <= $date){
				$this->mining($user_idx,$user_id,'MBM',$amount,$mbm);
				$this->mining($user_idx,$user_id,'ETH',$amount,$eth);
				$this->mining($user_idx,$user_id,'XMR',$amount,$xmr);
				$this->mining($user_idx,$user_id,'XCH',$amount,$xch);
			}
		}
		log_deadline("마이닝JOBINGS ", 'end');
	}
	
	function mining($user_idx,$user_id,$type,$amount,$bonus){
		$input['table'] = 'TB_MINING';
		$db_input['user_idx']							=	$user_idx;
		$db_input['user_id']							=	$user_id;
		$db_input['min_kind']							=	$type;
		$db_input['min_amount']						=	$amount;
		$db_input['min_bonus']						=	$bonus;
		$db_input['inputid']							=	'admin';
		$db_input['inputip']							=	$this->input->ip_address();
		insert($db_input,$input);

		#coin_exchange insert skm 2021.01.29
		$tid		= orderNo();

		switch($type) {
			case 'ETH': $action = '411'; $gubn	=	4; $symbol='eth';
						break;
			case 'MBM': $action = '412'; $gubn	=	4; $symbol='mbm';
						break;
			case 'XMR': $action = '413'; $gubn	=	4; $symbol='xmr';
						break;
			case 'BTT': $action = '416'; $gubn	=	4; $symbol='btt';
						break;
			case 'XCH': $action = '511'; $gubn	=	5; $symbol='xch';
						break;
			default : $action = '411'; $gubn	=	4; $symbol='eth'; break;
		}

		$input['table']	='coin_exchange_'.$symbol.'jobings';
		$exchange_db_input['user_id']			= $user_id;
		$exchange_db_input['user_idx']    = $user_idx;
		$exchange_db_input['from_id']			= '';
		$exchange_db_input['cpi_idx']			= '';
		$exchange_db_input['cecg_gubun']  = $gubn;
		$exchange_db_input['cecg_action'] = $action;
		$exchange_db_input['cecg_amount'] = $bonus;
		$exchange_db_input['cecg_tid']    = $tid;
		$exchange_db_input['cecg_paied']  = 'Y';
		$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;

		insert($exchange_db_input,$input);
	}
	/*
		2021-01-14 pse
		글로벌 보너스
		내 1대 직추천자의 추천인 1명 3명 5명 7명에 따라서
		1대 10% 1~3대 10% 1~7대 10% 1~10대 10% 로 글로벌 보너스를 받는다.
		1~10대 는 내 위에 직추천자가 아닌 내가 추천한 밑에 대수를 말한다.(downlevel)
	*/
	public function setDailyGlobalBonus(){
		log_deadline("글로벌JOBINGS보너스 ", 'start');
		$users = $this->Benefit_model->getGlobalUsers();
		$list	=	$users['page_list'];
		foreach ($list as $val) {
			$referral_cnt = $this->Benefit_model->getReferralUserCnt($val->cmm_id);
			$user_referral_cnt = $referral_cnt->cnt;
			$referral_level	=	$referral_cnt->level;
			$user_id 	= $val->cmm_id;
			$user_idx = $val->cmm_seq;
			
			if($user_referral_cnt > 0){
				$date_ymd = date('Y-m-d');
				
				#내 추천인 downlevel 회원들의 JOBINGS 받은 내역의 합산
				$total_bonus = $this->Benefit_model->getReferralTotalBonus($user_id,$referral_level,$date_ymd);
				
				if($total_bonus > 0){
					
					$input['table'] = 'TB_GLOBAL_BONUS';
					$db_input['user_idx'] 		= $user_idx;
					$db_input['user_id'] 			= $user_id;
					$db_input['from_idx'] 		= '';
					$db_input['from_id'] 			= '';
					$db_input['mining_idx'] 	= '';
					$db_input['global_bouns']	=	$total_bonus*0.1;
					$db_input['inputid']			=	'admin';
					$db_input['inputip']			=	$this->input->ip_address();
					insert($db_input,$input);

					$action		= '311';
					$tid		= orderNo();

					$exchange_input['table']	='coin_exchange_mbmjobings';
					$exchange_db_input['user_id']			= $user_id;
					$exchange_db_input['user_idx']    = $user_idx;
					$exchange_db_input['from_id']			= '';
					$exchange_db_input['cpi_idx']			= '';
					$exchange_db_input['cecg_gubun']  = 3;
					$exchange_db_input['cecg_action'] = $action;
					$exchange_db_input['cecg_amount'] = $total_bonus*0.1;
					$exchange_db_input['cecg_tid']    = $tid;
					$exchange_db_input['cecg_paied']  = 'Y';
					$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;

					insert($exchange_db_input,$exchange_input);
				}
			}
		}
		log_deadline("글로벌JOBINGS보너스 ", 'end');
	}

	/*
		직급수당
		전월 소실적매출기준
		24600PV :35%
		24600PV :30%
		24600PV :20%
		24600PV :10%
		24600PV :5%

		마이닝보너스 전체(MBM)보너스 10%
	*/

	public function gradeBonus(){
		log_deadline("직급수당", 'start');
		$TOTAL_AMOUNT = 0;
		$i=1;
		
		$RATE=array();

		$users = $this->Benefit_model->getPackageUsers();
		$list = $users['page_list'] ;
		foreach($list as $val){
			$user_id = $val->cpi_investor;
			$user_idx = $val->cmm_seq;
			if($user_id !='captain'){
				$getSupport		= $this->Benefit_model->getAllSupportPosition($user_id);
				
				$leftSupport	= $this->Benefit_model->getMonthTotalSailes($getSupport->LEFT_ID,$this->deadline_Ym);
				$leftSupport_bonus = $leftSupport->support_bouns;

				$rightSupport = $this->Benefit_model->getMonthTotalSailes($getSupport->RIGHT_ID,$this->deadline_Ym);
				$rightSupport_bonus = $rightSupport->support_bouns;
			
				$input['table']		= 'TB_GRADE_TOTAL_SALES';
				$input['user_id']	= $user_id;
				
				$sponsor_db_input['user_id']				= $user_id;
				$sponsor_db_input['left_amount']		= $leftSupport_bonus;
				$sponsor_db_input['right_amount']		= $rightSupport_bonus;
				$sponsor_db_input['close_date']			=	$this->Ymd;//마감월
				$sponsor_db_input['sales_date']			=	date("Y-m-d", strtotime($this->Ymd." -1 month"));//매출;

				$input['type'] ='insert';
				$this->Benefit_model->sponsorDeadline($sponsor_db_input,$input);

				if($leftSupport_bonus > 0 && $rightSupport_bonus > 0){
					if($leftSupport_bonus > $rightSupport_bonus){
						$TOTAL_AMOUNT = $rightSupport_bonus;
					}else{
						$TOTAL_AMOUNT = $leftSupport_bonus;
					}

					switch($TOTAL_AMOUNT) {
						case $TOTAL_AMOUNT >= 24600 and $TOTAL_AMOUNT < 73800: $RATE[1] = '0.35';$USER[1][]= $user_id;$USER_IDX[1][] = $user_idx;break;
						case $TOTAL_AMOUNT >= 73800 and $TOTAL_AMOUNT < 442800: $RATE[2] = '0.3';$USER[2][]= $user_id;$USER_IDX[2][] = $user_idx;break;
						case $TOTAL_AMOUNT >= 442800 and $TOTAL_AMOUNT < 2656800: $RATE[3] = '0.2';$USER[3][]= $user_id;$USER_IDX[3][] = $user_idx;break;
						case $TOTAL_AMOUNT >= 2656800 and $TOTAL_AMOUNT < 7970400: $RATE[4] = '0.1';$USER[4][]= $user_id;$USER_IDX[4][] = $user_idx;break;
						case $TOTAL_AMOUNT >= 7970400 : $RATE[5] = '0.05';$USER[5][]= $user_id;$USER_IDX[5][] = $user_idx;break;
						default:$rate = '0.35';break;
					}
					
					#마이닝보너스MBM(마감전월)데이터 확인 412코드
					$min_mbm_total_bonus = $this->Benefit_model->getMonthTotalbonus('',412,$this->deadline_Ym);//회원이 아닌 토탈 mbm
					
					#로그용
					if($TOTAL_AMOUNT >= 24600){
						echo $user_id."::".$TOTAL_AMOUNT."<br>";
						log_deadline("소실적",$user_id.":소실적:".$TOTAL_AMOUNT);
					}
				}
			}
		}

		#수당지급
		for($b=1;$b < 6;$b++) {
			$user = $USER[$b] ?? '';
			$user_idx = $USER_IDX[$b] ?? '';
			if($user){
				$min_mbm_bonus	= $min_mbm_total_bonus*0.1;
				$receve_bonus		= $min_mbm_bonus*$RATE[$b];
				$grade_bonus		= $receve_bonus/count($user);
				for($i=0;$i< count($user);$i++) {
					 $message= 'totla_mbm:'.(float)$min_mbm_bonus."::rate".$RATE[$b]." :grade_bonus:".(float)$grade_bonus."::M".$b." ".$user[$i];
					//echo $message."<br>";			
					log_deadline("지급내역",'totla_mbm:'.(float)$min_mbm_bonus."::rate".$RATE[$b]." :amount:".(float)$grade_bonus."::M".$b." ".$user[$i]);
					$this->_exchange($user[$i],$user_idx[$i],'',4,414,$grade_bonus,$message);
				}
			}
		}

		//echo "M1:".count($USER[1])." M2:".count($USER[2])." M3:".@count($USER[3])." M4:".@count($USER[4])." M5:".@count($USER[5])."<BR>";

		log_deadline("직급수당 ", 'End');
	}

	function _exchange($user_id,$user_idx,$from_id,$gubun,$action,$amount,$message) {
		$tid		= orderNo();
		
		if($gubun	==	1){
			$input['table']	='coin_exchange';
		}else{
			$input['table']	='coin_exchange_mbmjobings';
		}
		
		$exchange_db_input['user_id']			= $user_id;
		$exchange_db_input['user_idx']    = $user_idx;
		$exchange_db_input['from_id']			= $from_id;
		$exchange_db_input['cpi_idx']			= '';
		$exchange_db_input['cecg_gubun']  = $gubun;
		$exchange_db_input['cecg_action'] = $action;
		$exchange_db_input['cecg_amount'] = floor($amount);#소수점 절사처리
		$exchange_db_input['cecg_tid']    = $tid;
		$exchange_db_input['cecg_paied']  = 'Y';
		$exchange_db_input['cecg_comment']  = $message;
		$exchange_db_input['cecg_regdt']  = $this->YmdHis;
		$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;
		
		//printR($exchange_db_input);
		$result = $this->Exchange_model->exchange($exchange_db_input,$input);
		if($result == false) {
			log_deadline("exchange|error",$message."|".$this->db->last_query());
		}
	}

	/*
		2021.01.05 skm
		패키지별 MBM코인지급 2021.01.25까지 구매자
		종료
	*/
	public function setPackageMbm(){
		$invest_date = '2021-01-25';//2021.01.25까지
		$list = $this->Benefit_model->getPackageMbmList($invest_date);
		
		foreach ($list as $value) {
			switch($value->cpi_packagecode) {
				case 'M2':$pm_amount = 2000;break;
				case 'M3':$pm_amount = 6500;break;
				case 'M4':$pm_amount = 11000;break;
				case 'M5':$pm_amount = 15000;break;
				case 'M6':$pm_amount = 22000;break;	
			}

			$input['table'] = 'TB_PACKAGE_MBM';
			$db_input['user_id']	= $value->cpi_investor;
			$db_input['cpi_idx']	= $value->cpi_idx;
			$db_input['package_code']	= $value->cpi_packagecode;
			$db_input['pm_amount']		= $pm_amount;
			$db_input['inputid']	=	'admin';
			$db_input['inputip']	=	$this->input->ip_address();
			$this->Benefit_model->insert($db_input,$input);
		}
	}

	public function setMining(){
		exit;
		$date = '2021-02-01';
		$mining = $this->Exchange_model->getMiningList($date);
		$list	=	$mining['page_list'];
		foreach ($list as $val) {
			echo "id:".$val->user_id.":bonus:".$val->min_bonus.":date:".$val->inputdttm."<br>";

			$user_id	= $val->user_id;
			$user_idx = $val->user_idx;
			$bonus		=	$val->min_bonus;
			$min_kind = $val->min_kind;

			$getTid	= $this->Exchange_model->getTid();
			$tid		= $getTid['res']->cecg_tid;

			switch($min_kind) {
				case 'ETH': $action = '411';
							break;
				case 'MBM': $action = '412';
							break;
				case 'XMR': $action = '413';
							break;
				default : $action = '411';break;
			}

			$input['table']	='coin_exchange';
			$exchange_db_input['user_id']			= $user_id;
			$exchange_db_input['user_idx']    = $user_idx;
			$exchange_db_input['from_id']			= '';
			$exchange_db_input['cpi_idx']			= '';
			$exchange_db_input['cecg_gubun']  = 4;
			$exchange_db_input['cecg_action'] = $action;
			$exchange_db_input['cecg_amount'] = $bonus;
			$exchange_db_input['cecg_tid']    = $tid;
			$exchange_db_input['cecg_paied']  = 'Y';
			$exchange_db_input['cecg_regdt']	= $val->inputdttm;
			$exchange_db_input['cecg_payment_regdt']	= $val->inputdttm;
			
			$this->Exchange_model->exchange($exchange_db_input,$input);

			$res =  $this->Exchange_model->getbalance('4',$user_idx);
			$db_input['total_amount'] =$res->total_amount;
			$db_input['user_id']      =$user_id;
			$db_input['cecg_gubun']   =4;
			$this->Exchange_model->accountAmount($db_input,$input);
		}
	}

	public function setGlobal(){
		exit;
		$date = '2021-02-01';
		$mining = $this->Exchange_model->getGlobalBonus($date);
		$list	=	$mining['page_list'];
		foreach ($list as $val) {
			$user_id	= $val->user_id;
			$user_idx = $val->user_idx;
			$bonus		=	$val->global_bouns;
			$from_id	= $val->from_id;
			$action		= '311';

			$getTid	= $this->Exchange_model->getTid();
			$tid		= $getTid['res']->cecg_tid;

			$input['table']	='coin_exchange';
			$exchange_db_input['user_id']			= $user_id;
			$exchange_db_input['user_idx']    = $user_idx;
			$exchange_db_input['from_id']			= $from_id;
			$exchange_db_input['cpi_idx']			= '';
			$exchange_db_input['cecg_gubun']  = 3;
			$exchange_db_input['cecg_action'] = $action;
			$exchange_db_input['cecg_amount'] = $bonus;
			$exchange_db_input['cecg_tid']    = $tid;
			$exchange_db_input['cecg_paied']  = 'Y';
			$exchange_db_input['cecg_regdt']	= $val->inputdttm;
			$exchange_db_input['cecg_payment_regdt']	= $val->inputdttm;
			
			$this->Exchange_model->exchange($exchange_db_input,$input);

			$res =  $this->Exchange_model->getbalance('3',$user_idx);
			$db_input['total_amount'] =$res->total_amount;
			$db_input['user_id']      =$user_id;
			$db_input['cecg_gubun']   =3;
			$this->Exchange_model->accountAmount($db_input,$input);

		}
	}
	
	/*
		프로모션보너스(MBM)
		3개월후 매달 20%지급
		2020.03월(2020.12월매출)
	*/
	public function givePackageMbm(){
		$date	=	date('Y-m-d');
		$data = $this->Benefit_model->getPackagPromotion($date);
		$list	=	$data['page_list'];
		foreach ($list as $val) {
			$user_id	= $val->user_id;
			$user_idx = $val->user_idx;
			$idx			=	$val->pm_idx;
			$bonus		=	$val->pm_amount;
			$from_id	= '';
			$action		= '415';

			$tid		= orderNo();

			$input['table']	='coin_exchange_jobings';
			$exchange_db_input['user_id']			= $user_id;
			$exchange_db_input['user_idx']    = $user_idx;
			$exchange_db_input['from_id']			= $from_id;
			$exchange_db_input['cpi_idx']			= '';
			$exchange_db_input['cecg_gubun']  = 4;
			$exchange_db_input['cecg_action'] = $action;
			$exchange_db_input['cecg_amount'] = $bonus*0.2;//20%만 지급
			$exchange_db_input['cecg_tid']    = $tid;
			$exchange_db_input['cecg_paied']  = 'Y';
			$exchange_db_input['cecg_regdt']	= date('Y-m-d H:i:s');
			$exchange_db_input['cecg_payment_regdt']	= date('Y-m-d H:i:s');

			$this->Exchange_model->exchange($exchange_db_input,$input);

			$res =  $this->Exchange_model->getbalance('4',$user_idx);
			$db_input['total_amount'] =	$res->total_amount;
			$db_input['user_id']      =	$user_id;
			$db_input['cecg_gubun']   =	4;
			$this->Exchange_model->accountAmount($db_input,$input);
			
			#20%지급일 업데이트
			$package_input['table'] =	'TB_PACKAGE_MBM';
			$package_db_input['receiving_dt']	=	addTime(1,'month',$val->receiving_dt);
			$package_db_input['received_cnt']	=	$val->received_cnt+1;

 			$where['pm_idx'] = $idx;
			
			//echo 'id:::'.$user_id.'<br>';
			$this->Benefit_model->updateByParm($package_db_input, $package_input, $where);
		}
	}
	
	public function globalTest(){
		$users = $this->Benefit_model->getGlobalUsers();
		$list	=	$users['page_list'];
		$i =0;
		foreach ($list as $val) {
			$referral_cnt = $this->Benefit_model->getReferralUserCnt($val->cmm_id);
			$user_referral_cnt = $referral_cnt->cnt;
			$referral_level	=	$referral_cnt->level;
			$user_id 	= $val->cmm_id;
			$user_idx = $val->cmm_seq;
			
			if($user_referral_cnt > 0){
				//$date_ymd = date('Y-m-d');
				$date_ymd	=	'2021-05-18';
				/*
					유저 다운레벨 회원의 오늘 마이닐 데일리를 받았는지 체크 
				*/
				
				$total_bonus = $this->Benefit_model->getReferralTotalBonus($user_id,$referral_level,$date_ymd);
				
				
				if($total_bonus > 0){
					$i++;
					echo $user_id.'|'.$total_bonus;
					echo '<br>';
					
					$input['table'] = 'TB_GLOBAL_BONUS';
					$db_input['user_idx'] 		= $user_idx;
					$db_input['user_id'] 			= $user_id;
					$db_input['from_idx'] 		= '';
					$db_input['from_id'] 			= '';
					$db_input['mining_idx'] 	= '';
					$db_input['global_bouns']	=	$total_bonus*0.1;
					$db_input['inputdttm']		=	$date_ymd.' 01:00:00';
					$db_input['inputid']			=	'admin';
					$db_input['inputip']			=	$this->input->ip_address();
					$this->Benefit_model->insert($db_input,$input);
					
					
					$action		= '311';
					$getTid	= $this->Exchange_model->getTid();
					$tid		= $getTid['res']->cecg_tid;

					$exchange_input['table']	='coin_exchange_test';
					$exchange_db_input['user_id']			= $user_id;
					$exchange_db_input['user_idx']    = $user_idx;
					$exchange_db_input['from_id']			= '';
					$exchange_db_input['cpi_idx']			= '';
					$exchange_db_input['cecg_gubun']  = 3;
					$exchange_db_input['cecg_action'] = $action;
					$exchange_db_input['cecg_amount'] = $total_bonus*0.1;
					$exchange_db_input['cecg_tid']    = $tid;
					$exchange_db_input['cecg_paied']  = 'Y';
					$exchange_db_input['cecg_payment_regdt']	= $this->YmdHis;

				//$this->Exchange_model->exchange($exchange_db_input,$exchange_input);
				}
			}
		}
		echo "ok|".$i;
	}

	/*
		토탈후원매출,등급업데이트
	*/
	public function getEventTotalSupport(){
		$deadline_end_Ymd	='20211213';
		$close_datetime		='2021-12-13';

		$TOTAL_AMOUNT = 0;
		$i=1;
		$users = $this->Benefit_model->users();
		$list = $users['page_list'] ;
		foreach($list as $val){
			$user_id		= $val->cmm_id;
			$user_idx		= $val->cmm_seq;
			$user_level = $val->cmm_grade;
			
			if($user_id !='captain'){
				$getSupport		= $this->Benefit_model->getAllSupportPosition($user_id);
				
				$leftSupport	= $this->Benefit_model->getDeadLineTotalSupportBouns($getSupport->LEFT_ID,$deadline_end_Ymd);
				$leftSupport_bonus = $leftSupport->support_bouns;
				$rightSupport = $this->Benefit_model->getDeadLineTotalSupportBouns($getSupport->RIGHT_ID,$deadline_end_Ymd);
				$rightSupport_bonus = $rightSupport->support_bouns;
				
				if($leftSupport_bonus > 0 && $rightSupport_bonus > 0){
					if($leftSupport_bonus > $rightSupport_bonus) {
						$TOTAL_AMOUNT = $rightSupport_bonus;
					}else{
						$TOTAL_AMOUNT = $leftSupport_bonus;
					}

					switch($TOTAL_AMOUNT) {
						case $TOTAL_AMOUNT <  24600: $LEVEL = 'Master0';break;
						case $TOTAL_AMOUNT >= 24600 and $TOTAL_AMOUNT < 73800: $LEVEL = 'Master1';break;
						case $TOTAL_AMOUNT >= 73800 and $TOTAL_AMOUNT < 442800: $LEVEL = 'Master2';break;
						case $TOTAL_AMOUNT >= 442800 and $TOTAL_AMOUNT < 2656800: $LEVEL = 'Master3';break;
						case $TOTAL_AMOUNT >= 2656800 and $TOTAL_AMOUNT < 7970400: $LEVEL = 'Master4';break;
						case $TOTAL_AMOUNT >= 7970400 : $LEVEL = 'Master5';break;
						default:$LEVEL = 'Master0';break;
					}
					
					$sponsor_input['table']		= 'TB_EVENT_SPONSOR_TOTAL_SALES';
				
					$sponsor_db_input['user_id']				= $user_id;
					$sponsor_db_input['grade']					= $LEVEL;
					$sponsor_db_input['left_amount']		= $leftSupport_bonus;
					$sponsor_db_input['right_amount']		= $rightSupport_bonus;
					$sponsor_db_input['close_datetime']	=	$close_datetime;	
					insert($sponsor_db_input,$sponsor_input);
				}
			}
		}
	}
	
	#delete_user 회원 후원 및 후원추청매칭 못받도록 삭제 
	public function delUserBonus(){
		log_deadline("회원 수당 삭제", 'start');
		$delete_user	=	['sjk3230'];
		$bonus_list = $this->Benefit_model->getSupportBonusCnt($delete_user,$this->Ymd);
		$list = $bonus_list['page_list'] ;
		if($bonus_list['total_cnt'] > 0){
			foreach($list as $val){
				$user_id	=	$val->user_id;
				$bonus_idx	=	$val->cecg_idx;
				$bonus	=	$val->cecg_amount;
				$action	=	$val->cecg_action;
				
				$input['table']	=	'coin_exchange';
				$where['user_id']		=	$user_id;
				$where['cecg_idx']	=	$bonus_idx;
				delete($input, $where);
				
				$str	= $user_id.'|포인트:'.$bonus.'|action:'.$action.'(수당 삭제)';
				log_deadline("회원 수당 삭제", $str);
			}
		}
		log_deadline("회원 수당 삭제", 'end');
	}
}