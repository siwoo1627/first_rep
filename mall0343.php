<? php
while ($leftDate < 1) {
	 /**********************************************************************
	 *  주문수집 (주문상태 -> PAYED[결제완료])
	 **********************************************************************/
	$searchDay = date("Y-m-d", strtotime($strEndDate . " " . $leftDate . " day")); // 시작일 - 종료일 차이를 계산해서 검색 해당 날짜 추출
	$searchDayStart = $searchDay."T00:00:00+09:00";
	$searchDayEnd = date('Y-m-d', strtotime($searchDay." +1 day"))."T00:10:00+09:00";

	$scl = new NHNAPISCL();
	$loopYn = 0;
	$firstTimeChk = $searchDayStart;
	while ($loopYn < 1) {

        $service            = "SellerService41";

        if($customer_id == "a0022601"){
            $service            = "AlphaSellerService41";
        }

		$operation			= "GetChangedProductOrderList";
		$detailLevel		= "Full";
		$version            = "4.1";
		$targetUrl			= __ORD_URL;

		//계속 선언해줘야 함 안그럼 부정접근에러 출력됨
		$timestamp = $scl->getTimestamp();
		$signature = $scl->generateSign($timestamp . $service . $operation, __ORD_SecretKey);

//        $firstTimeChk = "2021-05-13T00:00:00+09:00";
//        $searchDayEnd = "2021-05-14T00:10:00+09:00";

		$mall_post = "
			<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:seller=\"http://seller.shopn.platform.nhncorp.com/\" >
				<soapenv:Header/>
				<soapenv:Body>
					<seller:GetChangedProductOrderListRequest>
						<seller:AccessCredentials>
							<seller:AccessLicense>" . __ORD_AccessLicense . "</seller:AccessLicense>
							<seller:Timestamp>" . $timestamp . "</seller:Timestamp>
							<seller:Signature>" . $signature . "</seller:Signature>
						</seller:AccessCredentials>
						<seller:RequestID></seller:RequestID>
						<seller:DetailLevel>Full</seller:DetailLevel>
						<seller:Version>4.1</seller:Version>
						<seller:InquiryTimeFrom>".$firstTimeChk."</seller:InquiryTimeFrom>
						<seller:InquiryTimeTo>" . $searchDayEnd . "</seller:InquiryTimeTo>
						<seller:LastChangedStatusCode>PAYED</seller:LastChangedStatusCode>
						<seller:MallID>" . $api_user_id . "</seller:MallID>
					</seller:GetChangedProductOrderListRequest>
				</soapenv:Body>
			</soapenv:Envelope>
		";
		// http post방식으로 요청 전송
		$rq = new HTTP_Request($targetUrl);
		$rq->addHeader("Content-Type", "text/xml;charset=UTF-8");
		$rq->addHeader("SOAPAction", $service . "#" . $operation);
		$rq->setBody($mall_post);
		$result = $rq->sendRequest();
		$response = $rq->getResponseBody();
		$content_log = iconv('UTF-8', 'cp949//ignore', $response);
        
		//23.03.23 BHLEE 임시로직 2~3일만 수집 진행
		$msg_first = date("Y-m-d H:i;s")."|[ORD]|[".$operation."]\n";
		$customer_dir="/public_html/log/linkerSRC/order/cartMallChk/".date("Ymd")."_".$customer_id."_apiChk.txt";
//		$file = @fopen($customer_dir, "a", 1);
//		@fwrite($file, $msg_first);
//		@fclose($file);


		$msg_first  = "[" . date("Ymd H:i:s") . "] - [주문수집1] - [" . $customer_id . "]\n";
		$msg_first .= "[" . $content_log . "] " . "\n";
		$current	= date("Ymd");
//		$customer_dir = $_SERVER[DOCUMENT_ROOT] . "/Order/log/mall0343/" . $customer_id . '_' . $current . "_mall0343_step1_scrap_bh.txt";
//		$file = @fopen($customer_dir, "a", 1);
//		@fwrite($file, $msg_first);
//		@fclose($file);
		flush();

		// 쓸데없는 태그들 정리
		$response = str_replace('soapenv:', '', $response);
		$response = str_replace('n:', '', $response);



		unset($parser);
		$parser = new XMLParser($response);
		$parser->Parse();

		$rootNd = $parser->document->body[0]->getchangedproductorderlistresponse[0];
		$ResponseType = trim($rootNd->responsetype[0]->tagData);

		//전문 자체 오류가 없는지 체크
		if ($ResponseType != 'SUCCESS')
		{
			$success = "N";
			$msg = "주문수집 오류[STEP1] : " . $err_msg . " - API ID를 확인해 주시기 바랍니다.";
			$order_list_array["successYn"]=$success;
			$order_list_array["msg"]=iconv("euc-kr","utf-8",$msg);
			echo $customer_id."<><>".$sucess."<><>".$mall_id."<><>".$user_id."<><>".json_encode($order_list_array);
			exit;
		}

		// 실 주문 데이터 파싱
		$ndOrdList = "";
		$ndOrdList = $rootNd->changedproductorderinfolist;




		unset($totCnt);
		unset($lastChk);
		preg_match('/<ReturnedDataCount>(.*?)<\/ReturnedDataCount>/',$response,$totCnt);
		preg_match('/<MoreDataTimeFrom>(.*?)<\/MoreDataTimeFrom>/',$response,$lastChk);
		preg_match('/<HasMoreData>(.*?)<\/HasMoreData>/',$response,$HasMoreData);

		$maxCntTmp = $totCnt[1]; // 총갯수
		$lastTimeChk = $lastChk[1];//핵심 시간
		$HasMoreData = $HasMoreData[1];//추가적으로 주문이 있는지 없는지

		if($HasMoreData != "true"){
			$loopYn++;
		}else{
			$firstTimeChk = $lastTimeChk;
		}

		/**********************************************************************
		* 차수 배열 넣기
		**********************************************************************/
		for ($pi = 0; $pi < count($ndOrdList); $pi++) {
			$ndDetailList =	$ndOrdList[$pi]->tagChildren;
			$ProductOrderID			= trim($ndOrdList[$pi]->productorderid[0]->tagData);			// 주문 Unique 아이디
			$ProductOrderStatus	= trim($ndOrdList[$pi]->productorderstatus[0]->tagData);	// 주문 상태값
			$IsReceiverAddressChanged	= trim($ndOrdList[$pi]->isreceiveraddresschanged[0]->tagData);	// 배송지 정보 수정 여부(34) - 선물하기

			/**********************************************************************
			* 주문 상세정보 가져오기
			**********************************************************************/
			$operation = "GetProductOrderInfoList";
			$timestamp = $scl->getTimestamp();
			$signature = $scl->generateSign($timestamp . $service . $operation, __ORD_SecretKey);
			$gKey = $scl->generateKey($timestamp, __ORD_SecretKey);

			$mall_post="
				<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:seller=\"http://seller.shopn.platform.nhncorp.com/\" >
					<soapenv:Header/>
					<soapenv:Body>
						<seller:GetProductOrderInfoListRequest>
							<seller:AccessCredentials>
								<seller:AccessLicense>" . __ORD_AccessLicense . "</seller:AccessLicense>
								<seller:Timestamp>" . $timestamp . "</seller:Timestamp>
								<seller:Signature>" . $signature . "</seller:Signature>
								</seller:AccessCredentials>
							<seller:RequestID></seller:RequestID>
							<seller:DetailLevel>" . $detailLevel . "</seller:DetailLevel>
							<seller:Version>" . $version . "</seller:Version>
							<seller:ProductOrderIDList>" . $ProductOrderID . "</seller:ProductOrderIDList>
						</seller:GetProductOrderInfoListRequest>
					</soapenv:Body>
				</soapenv:Envelope>
			";

			unset($rq);
			$rq = new HTTP_Request($targetUrl);
			$rq->addHeader("Content-Type", "text/xml;charset=UTF-8");
			$rq->addHeader("SOAPAction", $service . "#" . $operation);
			$rq->setBody($mall_post);
			$result = $rq->sendRequest();
			$response = $rq->getResponseBody();
			
			//23.03.23 BHLEE 임시로직 2~3일만 수집 진행
			$msg_first = date("Y-m-d H:i;s")."|[ORD]|[".$operation."]\n";
			$customer_dir="/public_html/log/linkerSRC/order/cartMallChk/".date("Ymd")."_".$customer_id."_apiChk.txt";
//			$file = @fopen($customer_dir, "a", 1);
//			@fwrite($file, $msg_first);
//			@fclose($file);

			$content_log11 = iconv('UTF-8', 'cp949//ignore', $response);

			$msg_first  = "[" . date("Ymd H:i:s") . "] - [주문수집2] - [" . $customer_id . "]\n";
			$msg_first .= "[" . $content_log11 . "] " . "\n";
			$current	= date("Ymd");
//			$customer_dir = $_SERVER[DOCUMENT_ROOT] . "/Order/log/mall0343/" . $customer_id . '_' . $current . "_mall0343_step2_scrap_bh.txt";
//			$file = @fopen($customer_dir, "a", 1);
//			@fwrite($file, $msg_first);
//			@fclose($file);
			flush();

			$response = str_replace('soapenv:', '', $response);
			$response = str_replace('n:', '', $response);
			unset($parser2);
			$parser2 = new XMLParser($response);
			$parser2->Parse();

			// 응답노드
			$rootNd2 = $parser2->document->body[0]->getproductorderinfolistresponse[0];
			$ResponseType = trim($rootNd2->responsetype[0]->tagData);

			if ($ResponseType != 'SUCCESS') {


				$err_msg	= utf2euc(src_exp($response, '<Message>', '</Message>'));
				$success	= "N";
				$msg			= "주문수집 오류[STEP2] : ".$err_msg;
				$order_list_array["successYn"]=$success;
				$order_list_array["msg"]=iconv("euc-kr","utf-8",$msg);
				echo $customer_id."<><>".$sucess."<><>".$mall_id."<><>".$user_id."<><>".json_encode($order_list_array);
				exit;
			}

			// 주문 데이터
			$ndDetail = $rootNd2->productorderinfolist;
			unset($arOrd);
			$PlaceOrderStatus	= trim($ndDetail[0]->productorder[0]->placeorderstatus[0]->tagData); // 발주확인 상태

			if (trim($ndDetail[0]->productorder[0]->productorderstatus[0]->tagData) == 'PAYED') {
				$pack_str =  $ndDetail[0]->productorder[0]->packagenumber[0]->tagData;

				if(@in_array($ProductOrderID, $pack_array[$pack_str])){
					continue;
				}else{
					$pack_array[$pack_str][] = $ProductOrderID;
				}
			}
		}
		/**********************************************************************
		* 차수 배열 넣기 끝
		**********************************************************************/


		/***************************************************************************************************
		* 시작
		***************************************************************************************************/
		for ($i = 0; $i < count($ndOrdList); $i++) {

			$ndDetailList =	$ndOrdList[$i]->tagChildren;

			$ProductOrderID			= trim($ndOrdList[$i]->productorderid[0]->tagData);			// 주문 Unique 아이디
			$ProductOrderStatus	= trim($ndOrdList[$i]->productorderstatus[0]->tagData);	// 주문 상태값
			$IsReceiverAddressChanged	= trim($ndOrdList[$i]->isreceiveraddresschanged[0]->tagData);	// 배송지 정보 수정 여부(34) - 선물하기

			$order_id_str = $ProductOrderID;

			if(in_array($ProductOrderID, $ord_dup_chk)){ $ord_dup_chk_debug[] = $ProductOrderID; continue; } //주문번호 중복시 continue;
			$ord_dup_chk[]		= $ProductOrderID;

			/* 속도 부하로 인해 기수집 걸러냄 끝 */
			/**********************************************************************
			* 주문 상세정보 가져오기
			**********************************************************************/
			$operation = "GetProductOrderInfoList";
			$timestamp = $scl->getTimestamp();
			$signature = $scl->generateSign($timestamp . $service . $operation, __ORD_SecretKey);
			$gKey = $scl->generateKey($timestamp, __ORD_SecretKey);

			$mall_post="
				<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:seller=\"http://seller.shopn.platform.nhncorp.com/\" >
					<soapenv:Header/>
					<soapenv:Body>
						<seller:GetProductOrderInfoListRequest>
							<seller:AccessCredentials>
								<seller:AccessLicense>" . __ORD_AccessLicense . "</seller:AccessLicense>
								<seller:Timestamp>" . $timestamp . "</seller:Timestamp>
								<seller:Signature>" . $signature . "</seller:Signature>
								</seller:AccessCredentials>
							<seller:RequestID></seller:RequestID>
							<seller:DetailLevel>" . $detailLevel . "</seller:DetailLevel>
							<seller:Version>" . $version . "</seller:Version>
							<seller:ProductOrderIDList>" . $ProductOrderID . "</seller:ProductOrderIDList>
						</seller:GetProductOrderInfoListRequest>
					</soapenv:Body>
				</soapenv:Envelope>
			";

			unset($rq);
			$rq = new HTTP_Request($targetUrl);
			$rq->addHeader("Content-Type", "text/xml;charset=UTF-8");
			$rq->addHeader("SOAPAction", $service . "#" . $operation);
			$rq->setBody($mall_post);
			$result = $rq->sendRequest();

			$response = $rq->getResponseBody();
			
			//23.03.23 BHLEE 임시로직 2~3일만 수집 진행
			$msg_first = date("Y-m-d H:i;s")."|[ORD]|[".$operation."]\n";
			$customer_dir="/public_html/log/linkerSRC/order/cartMallChk/".date("Ymd")."_".$customer_id."_apiChk.txt";
//			$file = @fopen($customer_dir, "a", 1);
//			@fwrite($file, $msg_first);
//			@fclose($file);


			$content_log11 = iconv('UTF-8', 'cp949//ignore', $response);



			flush();

			$response = str_replace('soapenv:', '', $response);
			$response = str_replace('n:', '', $response);



			unset($parser2);
			$parser2 = new XMLParser($response);
			$parser2->Parse();

			//응답 노드
			$rootNd2 = $parser2->document->body[0]->getproductorderinfolistresponse[0];
			$ResponseType = trim($rootNd2->responsetype[0]->tagData);


			if ($ResponseType != 'SUCCESS') {
				$err_msg	= utf2euc(src_exp($response, '<Message>', '</Message>'));
				$success	= "N";
				$msg			= "주문수집 오류[STEP3] : ".$err_msg;
				$order_list_array["successYn"]=$success;
				$order_list_array["msg"]=iconv("euc-kr","utf-8",$msg);
				echo $customer_id."<><>".$sucess."<><>".$mall_id."<><>".$user_id."<><>".json_encode($order_list_array);
				exit;
			}
			// 주문 데이터
			$ndDetail ="";
			$ndDetail = $rootNd2->productorderinfolist;

			if($local_ip_addr_t == "125.132.82.88") { // 176 페이지
					$content_log12 = iconv('UTF-8', 'cp949//ignore', $response);

					$msg_first  = "[" . date("Ymd H:i:s") . "] - [주문수집2] - [" . $customer_id . "]\n";
					$msg_first .= "[" . $content_log12 . "] " . "\n";
					$current	= date("Ymd");
//					$customer_dir = $_SERVER[DOCUMENT_ROOT] . "/Order/log/mall0343/" . $customer_id . '_' . $current . "_mall0343_step2_scrap_test.txt";
//					$file = @fopen($customer_dir, "a", 1);
//					@fwrite($file, $msg_first);
//					@fclose($file);
					flush();
			}
			unset($arOrd);

			$PlaceOrderStatus	= trim($ndDetail[0]->productorder[0]->placeorderstatus[0]->tagData); // 발주확인 상태

			if (trim($ndDetail[0]->productorder[0]->productorderstatus[0]->tagData) == 'PAYED') {

				// 암호화 필드 복호화 처리
				if (!trim($ndDetail[0]->productorder[0]->shippingaddress[0]->name[0]->tagData)){ // 수취인 이름(25)){
					continue; // 수취인명이 없다면 SKIP
				}
				$OptionManageCode = trim($ndDetail[0]->productorder[0]->optionmanagecode[0]->tagData); // 옵션관리코드

				/*ggg*/
				$max_row_order_info_query = "insert into order_info_maxcnt (num) values (null) ";
				mysql_query($max_row_order_info_query, $connect2);
				$max_row_order_info = mysql_insert_id($connect2);
				$copy_max_order_id = $max_order_info_id = str_pad(Trim($max_row_order_info), 10, "0", STR_PAD_LEFT);

				$order_date_tmp = trim($ndDetail[0]->order[0]->orderdate[0]->tagData);//주문일시

				$PaymentDate = "";
				//미샤 결제일 추가
				if($customer_id == "a0020157"){
					$PaymentDate = trim($ndDetail[0]->order[0]->paymentdate[0]->tagData);//결제일시
					$PaymentDate_tmp   = '';
					$PaymentDate_tmp   = explode("T",$PaymentDate);
					$PaymentDate_tmp_res  = explode(":",$PaymentDate_tmp[1]);

					$PaymentDate_time     = $PaymentDate_tmp_res[0];
					$PaymentDate_min      = $PaymentDate_tmp_res[1];

					$PaymentDate2 = $PaymentDate_tmp[0]." ".$PaymentDate_time.":".$PaymentDate_min.":00";
					$timestamp2 = strtotime($PaymentDate2." +9 hours");
					$PaymentDate =  date("YmdHis", $timestamp2 );
				}

				$orderDateTmp   = '';
				$orderDateTmp   = explode("T",$order_date_tmp);
				$orderDateTmp2  = explode(":",$orderDateTmp[1]);

				$order_time     = $orderDateTmp2[0];
				$order_min      = $orderDateTmp2[1];

				$after_date = $orderDateTmp[0]." ".$order_time.":".$order_min.":00";
				$timestamp = strtotime($after_date." +9 hours");
				$order_date_tmp =  date("YmdHis", $timestamp );

				$shipNo_tmp = trim($ndDetail[0]->order[0]->orderid[0]->tagData); // 장바구니 주문번호(1)
				$mall_order_id_tmp = trim($ndDetail[0]->order[0]->orderid[0]->tagData); // 장바구니 주문번호(1)
				$user_name_tmp = trim($ndDetail[0]->order[0]->orderername[0]->tagData); // 주문자 이름(3)
				$user_tel_tmp = trim($ndDetail[0]->order[0]->orderertel1[0]->tagData); // 주문자 연락처 1(4)
				$user_cel_tmp = trim($ndDetail[0]->order[0]->orderertel1[0]->tagData); // 주문자 연락처 1(4);
				$order_id_tmp = trim($ndDetail[0]->productorder[0]->productorderid[0]->tagData);//주문번호

				$mall_product_id = trim($ndDetail[0]->productorder[0]->productid[0]->tagData); // 상품 번호(9)
				$product_name_tmp = trim($ndDetail[0]->productorder[0]->productname[0]->tagData); // 상품명(10)
				$partner_mall_product_id_tmp = trim($ndDetail[0]->productorder[0]->sellerproductcode[0]->tagData); // 판매자 상품 코드(11);
				$sku_tmp = trim($ndDetail[0]->productorder[0]->productoption[0]->tagData); // 상품 옵션(옵션명)(12)
				$quantity_tmp = trim($ndDetail[0]->productorder[0]->quantity[0]->tagData); // 수량(13);

                if(
                    $ndDetail[0]->productorder[0]->branchbenefittype[0]->tagData == "PLUS_1" && 
                    $ndDetail[0]->productorder[0]->branchbenefitvalue[0]->tagData > 0 && 
                    is_numeric($ndDetail[0]->productorder[0]->branchbenefitvalue[0]->tagData)
                ){
                    $quantity_tmp = $quantity_tmp + $ndDetail[0]->productorder[0]->branchbenefitvalue[0]->tagData;
                }

//                echo "<xmp>";
//                print_r($quantity_tmp);
//                echo "</xmp>";
//                exit;

				$sale_price_tmp = trim($ndDetail[0]->productorder[0]->unitprice[0]->tagData); // 상품 가격(14)
				$baesong_bi_tmp = trim($ndDetail[0]->productorder[0]->deliveryfeeamount[0]->tagData); // 배송비 합계(16)
				$baesong_type_tmp = trim($ndDetail[0]->productorder[0]->shippingfeetype[0]->tagData); // 배송비 형태(선불/착불/무료)(17)


				$msg_tmp = trim($ndDetail[0]->productorder[0]->shippingmemo[0]->tagData); // 배송 메모(18)

				$gift_info_tmp = trim($ndDetail[0]->productorder[0]->freegift[0]->tagData); // 사은품(19);
				$receive_zipcode_tmp = trim($ndDetail[0]->productorder[0]->shippingaddress[0]->zipcode[0]->tagData); // 수취인 우편번호(20)
				$receive_tel_tmp = trim($ndDetail[0]->productorder[0]->shippingaddress[0]->tel1[0]->tagData); // 수취인 연락처1(23);
				$receive_cel_tmp = trim($ndDetail[0]->productorder[0]->shippingaddress[0]->tel2[0]->tagData); // 수취인 연락처2(24);
				$receive_tmp = trim($ndDetail[0]->productorder[0]->shippingaddress[0]->name[0]->tagData); // 수취인 이름(25)
				$total_price_tmp = trim($ndDetail[0]->productorder[0]->totalpaymentamount[0]->tagData); // 총 결제금액(26)
				$order_seq_tmp = trim($ndDetail[0]->productorder[0]->packagenumber[0]->tagData); // 묶음 배송 번호(31)
				$customs_number_tmp = trim($ndDetail[0]->productorder[0]->individualcustomuniquecode[0]->tagData); // 개인통관고유부호(33)
				$packing_cnt_tmp = count($pack_array[$order_seq_tmp]);
				$product_class = trim($ndDetail[0]->productorder[0]->productclass[0]->tagData); // 상품 종류(일반/추가상품 구분)(29)

				$SellerBurdenDiscountAmount = trim($ndDetail[0]->productorder[0]->sellerburdendiscountamount[0]->tagData); // 판매자 부담 할인액
				$SellerBurdenProductDiscountAmount = trim($ndDetail[0]->productorder[0]->sellerburdenproductdiscountamount[0]->tagData); // 판매자 부담 상품 할인 쿠폰 금액
				$SellerBurdenStoreDiscountAmount = trim($ndDetail[0]->productorder[0]->sellerburdenstorediscountamount[0]->tagData); // 판매자 부담 스토어 할인 금액
                
                
				$select_chk	 = " SELECT count(*) FROM ".$customer_id."_db.order_info WHERE order_id = '".trim($order_id_tmp)."' AND customer_id = '".trim($customer_id)."' AND ";
				$select_chk .= " mall_id = 'mall0343' AND mall_product_id = '".trim($mall_product_id)."' ";
				$result_chk = mysql_query($select_chk, $connect_host);
				$rows_chk		= mysql_fetch_row($result_chk);


				//주문수집시 connect(마스터 DB)가 오랜시간 대기하고 있어서 mysql server has gone away 에러발생. 커넥션이 끊기지 않게 더미 테이블 조회함.
				$mastr_query="select 1 from dual";
				mysql_query($mastr_query,$connect2);
                
//                if($local_ip_addr_t == "125.132.82.102") {
//                    $rows_chk[0] = 0;
//                }

				if ($rows_chk[0] > 0) {
					continue;
				}

				/*스마트스토어(장보기몰) 내부 로직들*/
				if (!$receive_cel_tmp || $receive_cel_tmp == '') { // 2016.08.03 적용 (핸드폰 번호가 없을 때 전화번호를 핸드폰으로 적용) - 박세은 요청
					$receive_cel_tmp = $receive_tel_tmp;
				}

				$productclass = trim($ndDetail[0]->productorder[0]->productclass[0]->tagData); // 상품 종류(일반/추가상품 구분)(29)
				if ($product_class == '단일상품' || $product_class == '조합형옵션' || $product_class == '조합형옵션상품') {
					$addPrdSku	= '';
				} else {
					$addPrdSku	= '[' . $product_class . '] ';
				}

				if ($product_class == '추가구성상품') {
					$product_name_tmp = '┗[추가상품]' . $product_name_tmp;
				}

				//주문분리 사용함(F) 업체일 경우 순수 sku만 담음
				if($add_stock_flag == 'F'){
					$sku_tmp = addslashes(trim($sku_tmp)); // 옵션
				}else{
					if ($addPrdSku != ''){
						$add_sku = addslashes($addPrdSku) . addslashes($sku_tmp);
						$only_sku = '';
					}
					else{
						$add_sku = '';
						$only_sku = addslashes($sku_tmp);
					}
					$sku_tmp = addslashes($addPrdSku.trim($sku_tmp)); // 옵션
				}

				// 주문금액수집 정보 가져오기 - 2013.07.15
				$select_customer_info = "SELECT etc3, etc4, etc6,other4 FROM customer_shop_info WHERE mall_id = 'mall0343' AND customer_id = '" . $customer_id . "' AND user_id = '" . $user_id . "'";
				$result_customer_info = mysql_query($select_customer_info, $connect2);
				$row_customer_info		= mysql_fetch_array($result_customer_info);
				$etc3 = $row_customer_info['etc3'];
				$etc4 = $row_customer_info['etc4'];
				$etc6 = $row_customer_info['etc6']; //정산예정금액 0원일경우 수집여부
				$other4 = $row_customer_info['other4']; //정산예정금액 0원일경우 수집여부

				if($customer_id =='a0000018' || $customer_id =='a0010429' || $customer_id =='a0017415' || $customer_id =='a0016643' ){
					$etc10 =  $other4;
				}

				//미수집 선택 업체이고 정산예정금액이 0보다 작거나 같으면 continue(미수집)
				$expectedSettlementAmount_tmp	= trim($ndDetail[0]->productorder[0]->expectedsettlementamount[0]->tagData); // 정산예정금액 (12.08.01추가)(28)
				$sku_price                      = trim($ndDetail[0]->productorder[0]->optionprice[0]->tagData); // 옵션 금액(15)


				if($etc6 == 'N'){
					if($expectedSettlementAmount_tmp <= 0){
						continue;
					}
				}

				if ($etc4 == '2') {
					$sale_price_tmp += $sku_price;
					if (!$etc3 || $etc3 == '1') { // 기존대로
						$total_price_tmp = $sale_price_tmp * $quantity_tmp; // 총판매가격 -> (상품가격 + 옵션가) * 주문수량
					}else{
						$total_price_tmp = str_replace(",", "", trim($total_price_tmp)); // 총판매가격 -> 총 결제금액
					}
				}else{
					if (!$etc3 || $etc3 == '1') { // 기존대로
						$total_price_tmp = (($sale_price_tmp + $sku_price) * $quantity_tmp); // 총판매가격 -> (상품가격 + 옵션가) * 주문수량
					}else{
						$total_price_tmp = str_replace(",", "", trim($total_price_tmp)); // 총판매가격 -> 총 결제금액
					}
				}

				$jungsan_price = '';
				if ($expectedSettlementAmount_tmp > 0) {
					$jungsan_price = $expectedSettlementAmount_tmp / $quantity_tmp;
				}

				$mall_supply_price  = $supply_price = str_replace(",", "", $jungsan_price);	// 정산예정금액 12.08.01 신규 추가
				$order_list_array['data'][$num]['supply_price'] = $supply_price; // 정산예정금액


				$expecteddeliverymethod = trim($ndDetail[0]->productorder[0]->expecteddeliverymethod[0]->tagData); // 배송 방법(30)

				if ($expecteddeliverymethod == 'VISIT_RECEIPT') {
					$msg_tmp = '[방문 수령] ' . $msg_tmp;
				} else if ($expecteddeliverymethod == 'DIRECT_DELIVERY') {
					$msg_tmp = '[직접 전달] ' . $msg_tmp;
				} else if ($expecteddeliverymethod == 'QUICK_SVC') {
					$msg_tmp = '[퀵서비스] ' . $msg_tmp;
				}

				$chu_baesong = trim($ndDetail[0]->productorder[0]->sectiondeliveryfee[0]->tagData); // 지역별 추가 배송비(32)
				if ($chu_baesong && $chu_baesong > 0) {
					$msg_tmp .= ' / 지역별 추가배송비 : ' . $chu_baesong;
				}

				if ($chu_baesong && $chu_baesong > 0) {
					$baesong_bi_tmp					= $chu_baesong + str_replace(",", "", trim($baesong_bi_tmp)); // 배송비
				} else {
					$baesong_bi_tmp					= str_replace(",", "", trim($baesong_bi_tmp)); // 배송비
				}

				$freegift = trim($gift_info_tmp); // 사은품
				if ($freegift) {
					$msg_tmp .= ' / 사은품 : ' . iconv('utf-8','cp949',$freegift);
					$gift_info_tmp =  addslashes($freegift);
				}



				if ($IsReceiverAddressChanged == 'true') {
					$msg_tmp .= ' / [선물하기 주문건]';
				}

				$user_tel = tel_make(iconv("utf-8","cp949",$scl->decrypt($gKey, $user_tel_tmp)));
				$user_cel = tel_make(iconv("utf-8","cp949",$scl->decrypt($gKey, $user_cel_tmp)));

                //수취인주소가 없으면 스킵
                if(!trim($ndDetail[0]->productorder[0]->shippingaddress[0]->baseaddress[0]->tagData)){
                    continue;
                }

				$register_date = date(YmdHis);
				$order_list_array["data"][$num]["id"] = $max_order_info_id;

				//미샤 결제일 추가
				$order_list_array["data"][$num]["pay_date"] = $PaymentDate;
				$order_list_array["data"][$num]["customer_id"] = $customer_id;
				$order_list_array["data"][$num]["mall_id"] = $mall_id;
				$order_list_array["data"][$num]["mall_name"] =  $mall_name;
				$order_list_array["data"][$num]["register_date"] =  $register_date;//등록 일
				$order_list_array["data"][$num]["customer_user_id"] = $user_id;
				$order_list_array["data"][$num]["customer_user_pwd"] = $user_pwd;
				$order_list_array["data"][$num]["order_date"] = $order_date_tmp;
				$order_list_array["data"][$num]["shipno"] = $shipNo_tmp;
				$order_list_array["data"][$num]["mall_order_id"] = $mall_order_id_tmp;
				$order_list_array["data"][$num]["user_name"] = iconv("utf-8","cp949",$scl->decrypt($gKey, $user_name_tmp));
				$order_list_array["data"][$num]["user_tel"] = $user_tel;
				$order_list_array["data"][$num]["user_cel"] = $user_cel;
				$order_list_array["data"][$num]["order_id"] = $order_id_tmp;
				$order_list_array["data"][$num]["mall_product_id"] = $mall_product_id;
				$order_list_array["data"][$num]["product_name"] = $product_name_tmp;
				$order_list_array["data"][$num]["partner_mall_product_id"] = $partner_mall_product_id_tmp;
				$order_list_array["data"][$num]["sku"] = $sku_tmp;
				$order_list_array["data"][$num]["quantity"] = $quantity_tmp;
				$order_list_array["data"][$num]["sale_price"] = $sale_price_tmp;
				$order_list_array["data"][$num]["baesong_bi"] = $baesong_bi_tmp;
				$order_list_array["data"][$num]["baesong_type"] = $baesong_type_tmp;
				$order_list_array["data"][$num]["msg"] = $msg_tmp;
				$order_list_array["data"][$num]["gift_info"] = $gift_info_tmp;
				$order_list_array["data"][$num]["receive_zipcode"] = $receive_zipcode_tmp;
				$order_list_array["data"][$num]["receive_tel"] = iconv("utf-8","cp949",$scl->decrypt($gKey, $receive_tel_tmp));
				$order_list_array["data"][$num]["receive_cel"] = iconv("utf-8","cp949",$scl->decrypt($gKey, $receive_cel_tmp));
				$order_list_array["data"][$num]["receive"] = iconv("utf-8","cp949",$scl->decrypt($gKey, $receive_tmp));
				$order_list_array["data"][$num]["total_price"] = $total_price_tmp;
				$org_total_price = $total_price_tmp;
				$order_list_array["data"][$num]["order_seq"] = $order_seq_tmp;

				//order_seq_tmp는 묶음배송번호
				if($customer_id == "a0020157sss"){
					$order_list_array["data"][$num]["shipno"] = $order_seq_tmp;
				}

				$order_list_array["data"][$num]["customs_number"] = $customs_number_tmp;
				$order_list_array["data"][$num]["packing_cnt"] = $packing_cnt_tmp;
				$order_list_array["data"][$num]["order_status"] = "002";
				$order_list_array["data"][$num]["delivery_status"] = "001";
				$order_list_array["data"][$num]["send_status"] = 'N';
				$order_list_array["data"][$num]["input_type"] = "001";

				//수취인 상세 주소(22)/ 수취인 기본 주소(21)
				$realAddr  = $scl->decrypt($gKey, trim($ndDetail[0]->productorder[0]->shippingaddress[0]->baseaddress[0]->tagData))." ";
				$realAddr .= $scl->decrypt($gKey, trim($ndDetail[0]->productorder[0]->shippingaddress[0]->detailedaddress[0]->tagData));
				$order_list_array["data"][$num]["receive_addr"] = iconv("utf-8","cp949",$realAddr);
                
                $add_msg = "";
                if($customer_id == "a0022601" || $customer_id == "a0023446"){
                    $add_msg  = $scl->decrypt($gKey, trim($ndDetail[0]->productorder[0]->shippingaddress[0]->baseaddress[0]->tagData))."<|>";
				    $add_msg .= $scl->decrypt($gKey, trim($ndDetail[0]->productorder[0]->shippingaddress[0]->detailedaddress[0]->tagData));
                    $add_msg = iconv("utf-8","cp949",$add_msg);
                }
                
                $pickuplocationtypeArr = array(
                    "FRONT_OF_DOOR" => "문 앞",
                    "MANAGEMENT_OFFICE" => "경비실 보관",
                    "DIRECT_RECEIVE" => "직접 수령",
                    "OTHER" => "기타"
                );

                $entryMethodArr = array(
                    "LOBBY_PW" => "공동현관 비밀번호 입력",
                    "MANAGEMENT_OFFICE" => "경비실 호출",
                    "FREE" => "자유 출입 가능",
                    "OTHER" => "기타 출입 방법"
                );

                $order_list_array["data"][$num]["add_msg"] = $add_msg;
                
                $isacceptalternativeproduct = "N";
                if($ndDetail[0]->productorder[0]->isacceptalternativeproduct[0]->tagData == "true"){
                    $isacceptalternativeproduct = "Y";
                }
                
                
                $pickuplocationtype  = $pickuplocationtypeArr[$ndDetail[0]->productorder[0]->shippingaddress[0]->pickuplocationtype[0]->tagData];
                $pickuplocationtype .= " ".$ndDetail[0]->productorder[0]->shippingaddress[0]->pickupLocationContent[0]->tagData;

                //특수문자 치환처리
                $doorPassword = iconv("utf-8","cp949",$scl->decrypt($gKey,$ndDetail[0]->productorder[0]->shippingaddress[0]->entrymethodcontent[0]->tagData));
                $doorPassword = str_replace("&gt;",">",$doorPassword);
                $doorPassword = str_replace("&lt;","<",$doorPassword);

                $msg2  = 'doorPassword<_>'.$doorPassword.'<dawninfo>';
                $msg2 .= 'zipCd<_><dawninfo>';
                $msg2 .= 'city<_><dawninfo>';
                $msg2 .= 'mallId<_>'.iconv("utf-8","cp949",$ndDetail[0]->productorder[0]->branchid[0]->tagData).'<dawninfo>';
                $msg2 .= 'mallNm<_><dawninfo>';
                $msg2 .= 'delvType<_><dawninfo>';
                $msg2 .= 'stateNm<_><dawninfo>';
                $msg2 .= 'delvTypeNm<_><dawninfo>';
                $msg2 .= 'slotId<_>'.iconv("utf-8","cp949",$ndDetail[0]->productorder[0]->slotid[0]->tagData).'<dawninfo>';
                $msg2 .= 'deliveryoperationdate<_>'.str_replace("-","",$ndDetail[0]->productorder[0]->deliveryoperationdate[0]->tagData).'<dawninfo>';
                $msg2 .= 'pickuplocationtype<_>'.$pickuplocationtype.'<dawninfo>';
                $msg2 .= 'entryMethod<_>'.$entryMethodArr[$ndDetail[0]->productorder[0]->shippingaddress[0]->entrymethod[0]->tagData].'<dawninfo>';
                $msg2 .= 'isacceptalternativeproduct<_>'.$isacceptalternativeproduct;
                $order_list_array["data"][$num]["msg2"] = str_replace("'","",$msg2);

				// 매입처 정보 가져오기 - 2013.04.11
				if ($order_id_tmp && $quantity_tmp && $product_name_tmp) {
					$select_customer_info = "SELECT etc_chk8 from customer_info where customer_id = '" . $customer_id . "'";
					$result_customer_info = mysql_query($select_customer_info, $connect2);
					$row_customer_info		= mysql_fetch_array($result_customer_info);
					$etc_chk8 = $row_customer_info['etc_chk8'];

					$select_product_basic_mallid	= "SELECT category_l, category_m, category_s, category_d, sale_price_p, supply_price_p, tax_yn, partner_id, md, product_id, ";
					$select_product_basic_mallid .= " shop_id, shopinfo_hidden1, shopinfo_hidden2, shopinfo_etc1, shopinfo_etc2, shopinfo_etc3, shopinfo_etc4, shopinfo_etc5, ";
					$select_product_basic_mallid .= " shopinfo_etc6, product_name, supply_price, partner_product_id, register, customer_id, mall_product_id, sale_price ";
					$select_product_basic_mallid .= " FROM join_product_t WHERE customer_id = '" . $customer_id . "' AND mall_id = 'mall0343' AND mall_product_id = '" . $mall_product_id . "' ";
					$select_product_basic_mallid .= " ORDER BY register_date DESC LIMIT 1";

					$result_product_basic_mallid	= mysql_query($select_product_basic_mallid, $connect_prod);
					$rows_product_basic_mallid		= mysql_fetch_array($result_product_basic_mallid);

					if (mysql_error()) {
						echo mysql_error() . "<br> 관리자에게 문의 바랍니다";
						exit;
					}

					$tax_yn             = trim($rows_product_basic_mallid[tax_yn]);          // 과세여부
					$category_l         = trim($rows_product_basic_mallid[category_l]);
					$category_m         = trim($rows_product_basic_mallid[category_m]);
					$category_s         = trim($rows_product_basic_mallid[category_s]);
					$category_d         = trim($rows_product_basic_mallid[category_d]);      // 카테고리
					$sale_price_p       = trim($rows_product_basic_mallid[sale_price_p]);    // 제휴사 판매가
					$supply_price_p     = trim($rows_product_basic_mallid[supply_price_p]);  // 제휴사 공급가
					$md_name            = trim($rows_product_basic_mallid[md]);              // 엠디명
					$product_id         = trim($rows_product_basic_mallid[product_id]);      // 상품아이디
					$mall_shop_id       = trim($rows_product_basic_mallid[shop_id]);
					$hidden1            = trim($rows_product_basic_mallid[hidden1]);
					$hidden2            = trim($rows_product_basic_mallid[hidden2]);
					$mall_etc1          = trim($rows_product_basic_mallid[etc1]);
					$mall_etc2          = trim($rows_product_basic_mallid[etc2]);
					$mall_etc3          = trim($rows_product_basic_mallid[etc3]);
					$mall_etc4          = trim($rows_product_basic_mallid[etc4]);
					$mall_etc5          = trim($rows_product_basic_mallid[etc5]);
					$mall_etc6          = trim($rows_product_basic_mallid[etc6]);
					$partner_product_id = trim($rows_product_basic_mallid[partner_product_id]);

					$origin_partner_id = "";
					if ($etc_chk8 == 'prod_db') {
						$select_product_etc = "SELECT partner_id FROM product WHERE product_id = '" . $product_id . "'";
						$result_product_etc = mysql_query($select_product_etc, $connect_prod);
						$row_product_etc = mysql_fetch_array($result_product_etc);
						$origin_partner_id = $supply_id = trim($row_product_etc['partner_id']);
					} else {
						$supply_id = trim($rows_product_basic_mallid[partner_id]); // 파트너아이디
					}

					if($customer_id == "a0020157"){
						if($mall_shop_id == ""){
							$mall_shop_id = $other4;
						}
					}

					$order_list_array['data'][$num]['tax_yn'] = $tax_yn;
					$order_list_array['data'][$num]['category_l'] = $category_l;
					$order_list_array['data'][$num]['category_m'] = $category_m;
					$order_list_array['data'][$num]['category_s'] = $category_s;
					$order_list_array['data'][$num]['category_d'] = $category_d;
					$order_list_array['data'][$num]['sale_price_p'] = $sale_price_p;
					$order_list_array['data'][$num]['supply_price_p'] = $supply_price_p;
					$order_list_array['data'][$num]['md_name'] = $md_name;
					$order_list_array['data'][$num]['product_id'] = $product_id;
					$order_list_array['data'][$num]['shop_id'] = $mall_shop_id;
					$order_list_array['data'][$num]['hidden1'] = $hidden1;
					$order_list_array['data'][$num]['hidden2'] = $hidden2;
					$order_list_array['data'][$num]['etc2'] = $mall_etc2;
					$order_list_array['data'][$num]['etc3'] = $mall_etc3;
					$order_list_array['data'][$num]['etc4'] = $mall_etc4;
					$order_list_array['data'][$num]['etc5'] = $mall_etc5;
					$order_list_array['data'][$num]['etc7'] = $etc7;
					$order_list_array['data'][$num]['partner_product_id'] = $partner_product_id;

					if(!$reg_register){ $reg_register = $ALL_USER_ID; }
					if($auto_schedule=="Y"){
						$reg_register="Schedule_A";
						if($schedule_type=="once")$reg_register="Schedule_B";
					}

					$order_list_array['data'][$num]['reg_register'] = $register;
					$order_list_array['data'][$num]['supply_id'] = $supply_id;
					if( $order_list_array['data'][$num]['product_name'] == '' ) $order_list_array['data'][$num]['product_name'] = $product_name_tmp;

					// 브랜드 select
					if ($product_id) {
						$p_sql_query = "";
						$p_sql_query = "SELECT brand FROM product WHERE product_id = '" . $product_id . "' ";
						//$p_sql = mysql_query($p_sql_query, $connect2);
						$p_sql = mysql_query($p_sql_query, $connect_prod);
						$p_row = mysql_fetch_array($p_sql);
						$brand_info = addslashes($p_row['brand']);
						$order_list_array['data'][$num]['brand_info'] = $brand_info;
					}

					// 케이엔코리아 요청 - DB 공급가 먼저, 없으면 쇼핑몰 공급가 2017.11.16
					if($customer_id == "a0014581"){
						$supply_price = Trim($rows_product_basic_mallid[supply_price]);
						if(!$rows_product_basic_mallid[supply_price]) $supply_price = $mall_supply_price;

					}else{
						if (!$supply_price) {
							$supply_price = trim($rows_product_basic_mallid[supply_price]);
						}
					}

					if ($supply_id) {
						$select_partner_info	= "SELECT bae_type FROM partner_info WHERE partner_id = '" . $supply_id . "'";
						$query_partner_info		= mysql_query($select_partner_info, $connect2);
						$row_partner_info			= mysql_fetch_row($query_partner_info);
						$partner_bae_type			= trim($row_partner_info[0]);
					}
					if (!$partner_bae_type) $partner_bae_type = "자사배송";
					$order_list_array['data'][$num]['etc1'] = $partner_bae_type;



					// 재고 관련 옵션 매칭 시작 - 2015.02.02
					$mapping_mall_id = '';
					$mapping_mall_product_id = '';
					$mapping_sku = '';
					$mapping_out_match_id = '';
					$mapping_partner_id = '';

					$mapping_mall_id            = "mall0343";					// 몰아이디 (각 몰별 매칭되는 데이터)
					$mapping_mall_product_id	= $mall_product_id;		// 쇼핑몰상품코드 (각 몰별 매칭되는 데이터)
					$mapping_sku                = $sku_tmp;								// 옵션항목 (각 몰별 매칭되는 데이터)

					$select_mapping = "select * from stock_mapping where mall_id = '" . $mapping_mall_id . "' and mall_product_id = '" . $mapping_mall_product_id . "' and mall_sku_value = '" . $mapping_sku . "' order by num desc";

					if($customer_id == 'a0001555'){
						$select_mapping = "select * from ".$customer_id."_db.stock_mapping where mall_id = '" . $mapping_mall_id . "' and mall_product_id = '" . $mapping_mall_product_id . "' and mall_sku_value = '" . $mapping_sku . "' order by num desc";
					}

					$result_mapping = mysql_query($select_mapping, $connect_prod);
					$row_mapping = mysql_fetch_array($result_mapping);

					$mapping_id = $row_mapping['num'];

					if ($mapping_id) {
						$mapping_out_match_id = $row_mapping['out_match_id'];
						$mapping_partner_id = $row_mapping['partner_id'];

						if ($mapping_partner_id){
							$supply_id = $mapping_partner_id;
						}else if($origin_partner_id){
							$supply_id = $origin_partner_id;
						}
						$order_list_array['data'][$num]['supply_id'] = $supply_id;
						if($customer_id == 'a0001555'){
							$order_list_array['data'][$num]['out_match_id'] = $mapping_out_match_id;
						}
					}

					// 공급가 매칭 시작 - 2015.09.24
					$stock_sku_id = $row_mapping['sku_id'];
					if ($stock_sku_id) {
						$select_stock_supply_price = "select sku_supply_price from sku where sku_id = '" . $stock_sku_id . "' and customer_id = '" . $customer_id . "'";
						$result_stock_supply_price = mysql_query($select_stock_supply_price, $connect_prod);
						$row_stock_supply_price = mysql_fetch_array($result_stock_supply_price);
						$stock_supply_price = $row_stock_supply_price['sku_supply_price'];

						if ($stock_supply_price > 0) {
							$supply_price_p = $stock_supply_price;
							$order_list_array['data'][$num]['supply_price_p'] = $supply_price_p;
						}
					}


					$sellerproductcode = "";
					if($order_list_array["data"][$num]["sku"] == "" && $OptionManageCode == ""){//옵션이 없는 경우는 단품으로 판매자 상품 코드(판매자가 임의로 지정)을 넣어준다
						$sellerproductcode = trim($ndDetail[0]->productorder[0]->sellerproductcode[0]->tagData);
						if($sellerproductcode != ""){
							$OptionManageCode = $sellerproductcode;
						}
					}


                    //2021-02-26 정책변경 펫츠비 우선 적용 옵션이 없은 단품의 경우 SCM은 무시한채 샵링커만 바라본다. 그러므로 product_id가 없으면 무조껀 빈값이다
                    if($customer_id == "a0023446" && $order_list_array["data"][$num]["sku"] == ""){
                        if($product_id){
                            $OptionManageCode = $partner_product_id;
                        }else{
                            $OptionManageCode = "";
                        }
                    }

                    //랄프로렌은 옵션코드가 20자 밖에 안됨 랄프로렌 SKU 전용 로직임
                    if($customer_id == "a0022894" && $sku_tmp != ""){
                        if($rows_product_basic_mallid[partner_product_id] != "" && $OptionManageCode != "" && $rows_product_basic_mallid[product_id] != ""){
                            $RL_TMP_CODE = $rows_product_basic_mallid[partner_product_id].$OptionManageCode;
                            $selCodeQry = "select * from product where partner_product_id='".$RL_TMP_CODE."'";
                            $selCodeRes = mysql_query($selCodeQry, $connect_prod);
                            $selCodeRow = mysql_fetch_array($selCodeRes);
                            $selCodeCnt = mysql_num_rows($selCodeRes);

                            //반드시 한개만 조회되어야함 1개 이상일때는 그냥 초기화해버림
                            if($selCodeCnt == "1"){
                                $OptionManageCode = $RL_TMP_CODE;
                            }else{
                                $OptionManageCode = "";
                            }
                        }else{
                            $OptionManageCode = "";
                        }
                    }


					//초기화 필수
					$partner_order_id = "";
					if($stock_flag_t == "A"){
						$sku_match_name = "";

						unset($sku_array_tmp);
						unset($sku_array);
						unset($single_sku_array);

						//옵션 관리코드가 있다면 partner_order_id는 바로 넣고 단품코드 매칭은 제외시킴
						if($OptionManageCode !=''){

							$partner_order_id = $OptionManageCode;

						}else{

							//하림.에프앤코은 옵션이 없는경우 판매자상품코드로 매칭 해준다
							//판매자코드로 매칭되는거 에프앤코 제외
							if(($customer_id =='a0013651' ) && $OptionManageCode=='' )
							{
								$partner_order_id= $partner_mall_product_id;
							}

							if($product_id){
								/**************************************************************
								*	스토어팜
								*	없음
								*	1조합 	사이즈: 100 -
								*	2조합 	사이즈/색상: 소/레드
								*	조합 구분자 - " : "(콜론)
								****************************************************************/
								$sku_array_tmp=explode(":",$sku_tmp);
								$sku_array=explode("/",$sku_array_tmp[1]);
								$sku_match_name=$sku_array[0];	//1조합
								if($sku_array[1]){	//2조합
									$sku_match_name=$sku_array[0]."_".$sku_array[1];
								}

								/*******************************
								*	공통부분
								********************************/
								$match_product_query="SELECT * FROM product WHERE product_id='".$product_id."'";
								$result_match_product=mysql_query($match_product_query,$connect_prod);
								$row_match_product=mysql_fetch_array($result_match_product);
								$partner_order_id=$partner_product_id;
								if($row_match_product[single_product_id]!=""){
									unset($single_sku_array);
									$single_sku_array=explode(",",$row_match_product[single_product_id]);

									for($sii=0; $sii<count($single_sku_array); $sii++){
										$single_product_info=explode("/",$single_sku_array[$sii]);

										$single_product_query="SELECT * FROM product WHERE product_id='".$single_product_info[0]."'";
										$result_match_product=mysql_query($single_product_query,$connect_prod);
										$row_single_product=mysql_fetch_array($result_match_product);

										if($row_single_product[group_attribute_code]=="999999999"){
											if(count($single_product_info)>3){          // 단품명에  /글자가 들어가는 경우.
												$single_product_info2=explode("/",$single_sku_array[$sii],2);       // product_id 추출
												$single_product_info3=implode("/",explode("/",$single_product_info2[1],-1));    // single_product_id에서 product_id, 추가가격 뺀 글자만 추출
												if(substr($single_product_info3,-1) == "/")  $single_product_info3 = substr($single_product_info3 , 0, -1);
												$single_product_info[1] = $single_product_info3;
											}
											$single_product_info[2] = $single_product_info[1];
											$sku_match_name=$sku_array_tmp[1]; // 속성없음일 경우는 옵션 1조합 형태임. / 글자 들어올 수 있음.
										}

										$partner_order_id = "";
										if( trim($single_product_info[2]) == trim($sku_match_name)){
											$match_query="SELECT * FROM product WHERE product_id='".$single_product_info[0]."'";
											$result_match=mysql_query($match_query,$connect_prod);
											$row_match=mysql_fetch_array($result_match);
											$partner_order_id=$row_match[partner_product_id];
											break;
										}
									}
								}
							}
						}

						// 공급가 매칭 끝
						include "/public_html/linker/Order/Join/stock_code_log.inc";
						$orign_num_seq = $num; // 원주문의 seq  - 2019.16.14 추가 적용


						$order_list_array["data"][$num]["partner_order_id"] = $partner_order_id;
						$order_list_array['data'][$num]['etc5'] = $mall_etc5;

						//세트단품 1유형
						//셋트 단품 적용
						$set_prd_partner_product_id="";
						$set_prd_product_name = "";
						$set_prod_qty = "";
						$set_customer_allow=array("a0014936aaa","a0013020aaaa");
						if(in_array($customer_id,$set_customer_allow)){
							//셋트단품여부 추가
							$set_sku_query= "SELECT * FROM ".$customer_id."_db.product WHERE partner_product_id='".$partner_order_id."'";
							$result_set_sku = mysql_query($set_sku_query,$connect_prod);
							$row_set_sku = mysql_fetch_array($result_set_sku);

							/*********************************
							- 셋트 상품 적용여부
							- json타입으로 처리
							- item_type = 001
							**********************************/
							$orign_num1="";

							if($row_set_sku[set_single_product_id]){
								$set_json_data = json_decode($row_set_sku[set_single_product_id],true);

								for($seti=0; $seti<count($set_json_data); $seti++){

									$set_query2= "SELECT * FROM ".$customer_id."_db.product WHERE product_id='".$set_json_data[$seti][product_id]."'";
									$set_result2 = mysql_query($set_query2,$connect_prod);
									$set_row2 = mysql_fetch_array($set_result2);
									$set_prod_qty = $set_json_data[$seti][qty]*$order_list_array["data"][$num]["quantity"];
									$max_row_order_info_query = "insert into order_info_maxcnt (num) values (null) ";
									mysql_query($max_row_order_info_query, $connect2);
									$max_row_order_info = mysql_insert_id($connect2);
									$max_order_info_id = str_pad(Trim($max_row_order_info), 10, "0", STR_PAD_LEFT);

									if($seti>0){
										$orign_num1 = $num;
										$num = $num+1;

										//상위에 num 증가하여 -1 하여 그전 주문 카피.
										$order_list_array["data"][$num] = $order_list_array["data"][($orign_num1)];
										$order_list_array["data"][$num]["supply_price"]="0";
										$order_list_array["data"][$num]["sale_price"]="0";
										$order_list_array["data"][$num]["total_price"]="0";
									}

									$order_list_array["data"][$num]["id"]=$max_order_info_id;
									$order_list_array["data"][$num]["quantity"]=$set_prod_qty;
									$order_list_array["data"][$num]["item_type"]="001";
									$order_list_array["data"][$num]["product_name"] = iconv("utf-8","euc-kr",base64_decode($set_json_data[$seti][product_name]));
									$order_list_array["data"][$num]["partner_order_id"]=$set_row2[partner_product_id];
								}
							}//셋트 END
						}

						//세트단품 2유형
						//셋트 단품 적용 베타버전 적용 2차적용 - 2019.06.03이후로 사용하는 업체는 이항목 적용.
						$set_customer_allow2=array("a0013020","a0018894","a0020157","a0023446","a0023537","a0022088","a0022601");
						if(in_array($customer_id,$set_customer_allow2)){

							//셋트단품여부 추가
							$set_sku_query= "SELECT * FROM ".$customer_id."_db.product WHERE partner_product_id='".$partner_order_id."'";
							$result_set_sku = mysql_query($set_sku_query,$connect_prod);
							$row_set_sku = mysql_fetch_array($result_set_sku);


							/*********************************
							- 셋트 상품 적용여부
							- json타입으로 처리
							- item_type = 001
							**********************************/
							$orign_num1=""; //분리된 주문 seq별도 생성
							if($row_set_sku[set_single_product_id]){
								$set_json_data = json_decode($row_set_sku[set_single_product_id],true);
								$order_list_array["data"][$orign_num_seq]['item_type']="P"; //원주문의 item_type가 null 상태를 패키지 주문으로(P) 적용
								for($seti=0; $seti<count($set_json_data); $seti++){

									$set_query2= "SELECT * FROM ".$customer_id."_db.product WHERE product_id='".$set_json_data[$seti][product_id]."'";
									$set_result2 = mysql_query($set_query2,$connect_prod);
									$set_row2 = mysql_fetch_array($set_result2);
									$set_prod_qty = $set_json_data[$seti][qty] * $quantity_tmp;
									$max_row_order_info_query = "insert into order_info_maxcnt (num) values (null) ";
									mysql_query($max_row_order_info_query, $connect2);
									$max_row_order_info = mysql_insert_id($connect2);
									$max_order_info_id = str_pad(Trim($max_row_order_info), 10, "0", STR_PAD_LEFT);

									$orign_num1 = $num;
									$num = $num+1;

									//원 주문 카피하기 위해서.
									$order_list_array["data"][$num] = $order_list_array["data"][$orign_num1];

									$order_list_array["data"][$num]["id"]=$max_order_info_id;
									$order_list_array["data"][$num]["quantity"]=$set_prod_qty;
									$order_list_array["data"][$num]["sku"]="";
									$order_list_array["data"][$num]["item_type"]="001";
									$order_list_array["data"][$num]["product_name"]=iconv("utf-8","euc-kr",base64_decode($set_json_data[$seti][product_name]));
									$order_list_array["data"][$num]["partner_order_id"]=$set_row2[partner_product_id];

									if($customer_id == "a0020157" || $customer_id == "a0023537" || $customer_id == "a0023446" || $customer_id == "a0022601"){
										$order_list_array["data"][$num]["sku"] = $sku_tmp;
										if($seti>0){
											$order_list_array["data"][$num]["supply_price"]="0";
											$order_list_array["data"][$num]["sale_price"]="0";
											$order_list_array["data"][$num]["total_price"]="0";
										}
									}else{
										$order_list_array["data"][$num]["supply_price"]="0";
										$order_list_array["data"][$num]["sale_price"]="0";
										$order_list_array["data"][$num]["total_price"]="0";
									}


									$order_list_array["data"][$num]["org_order_id"]=$copy_max_order_id;
								}
							}//셋트 END
						}
					}
				}
                
                //펫츠비 할인금액 별도 요청
                if($customer_id == "a0022601" || $customer_id == "a0023446"){
                    $productdiscountamount = trim($ndDetail[0]->productorder[0]->productdiscountamount[0]->tagData); // 판매자 부담 할인액
                    $SellerBurdenDiscountAmount = $productdiscountamount;
                    $SellerBurdenProductDiscountAmount = "";
                    $SellerBurdenStoreDiscountAmount = "";
                }

				// 쇼핑몰 쿠폰, 할인금액 추가 2018.02.27 16:06 by pew
				$order_sub_query = "INSERT INTO order_info_sub SET id='".$max_order_info_id."',";

                //셋트주문의 경우 max_order_info_id가 바뀌어서 들어오게되므로 정확한 처리가 불가능
                if($copy_max_order_id != "" && ($customer_id == "a0022601" || $customer_id == "a0023446")){
                    $order_sub_query = "INSERT INTO order_info_sub SET id='".$copy_max_order_id."',";
                    $order_sub_query .= " customer_id='".$customer_id."',";
                }

				$order_sub_query .= " mall_id='".$mall_id."',";
				$order_sub_query .= " order_id='".$order_id_tmp."',";
				$order_sub_query .= " mall_product_id='".$mall_product_id."',";
				$order_sub_query .= " product_id='".$product_id."',";
				$order_sub_query .= " product_name='".$product_name_tmp."',";
				$order_sub_query .= " register_date='".$register_date."',";	// 판매자 할인 금액
				$order_sub_query .= " dis_price_seller='".str_replace(",","",$SellerBurdenDiscountAmount)."',";	// 판매자 부담 할인액
				$order_sub_query .= " dis_price_coupon='".str_replace(",","",$SellerBurdenProductDiscountAmount)."',";	// 판매자 부담 상품 할인 쿠폰 금액
				$order_sub_query .= " dis_price_mall='".str_replace(",","",$SellerBurdenStoreDiscountAmount)."'";	// 판매자 부담 상품 할인 쿠폰 금액
				$result_order_sub = mysql_query($order_sub_query,$connect_prod);
				$PlaceOrderStatus	= trim($ndDetail[0]->productorder[0]->placeorderstatus[0]->tagData); // 발주확인 상태

				if (
					$PlaceOrderStatus == "NOT_YET" &&
					$other3 == "Y" &&
					$customer_id != 'a0003148' &&
					$customer_id != 'a0009344' &&
					$customer_id != 'a0009428' &&
					$customer_id != 'a0008951' &&
					$customer_id != 'a0022601' && $local_ip_addr_t != "125.132.82.58"
				) {
					$operation = "PlaceProductOrder";
					$targetUrl = __ORD_URL;
					$timestamp = $scl->getTimestamp();
					$signature = $scl->generateSign($timestamp . $service . $operation, __ORD_SecretKey);

					$mall_post="
						<soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\" xmlns:seller=\"http://seller.shopn.platform.nhncorp.com/\" >
							<soapenv:Header/>
							<soapenv:Body>
								<seller:" . $operation . "Request>
									<seller:AccessCredentials>
										<seller:AccessLicense>" . __ORD_AccessLicense . "</seller:AccessLicense>
										<seller:Timestamp>" . $timestamp . "</seller:Timestamp>
										<seller:Signature>" . $signature . "</seller:Signature>
									</seller:AccessCredentials>
									<seller:RequestID></seller:RequestID>
									<seller:DetailLevel>" . $detailLevel . "</seller:DetailLevel>
									<seller:Version>" . $version . "</seller:Version>
									<seller:ProductOrderID>" . $ProductOrderID . "</seller:ProductOrderID>
								</seller:" . $operation . "Request>
							</soapenv:Body>
						</soapenv:Envelope>
					";
					unset($rq);
					$rq = new HTTP_Request($targetUrl);
					$rq->addHeader("Content-Type", "text/xml;charset=UTF-8");
					$rq->addHeader("SOAPAction", $service . "#" . $operation);
					$rq->setBody($mall_post);
					$result = $rq->sendRequest();

					$content_log11 = iconv('UTF-8', 'cp949//ignore', $response);

					//23.03.23 BHLEE 임시로직 2~3일만 수집 진행
					$msg_first = date("Y-m-d H:i;s")."|[ORD]|[".$operation."]\n";
					$customer_dir="/public_html/log/linkerSRC/order/cartMallChk/".date("Ymd")."_".$customer_id."_apiChk.txt";
//					$file = @fopen($customer_dir, "a", 1);
//					@fwrite($file, $msg_first);
//					@fclose($file);

					/**********************************************************************
					* XML parsing
					**********************************************************************/
					$response = $rq->getResponseBody();
					$response = str_replace('soapenv:', '', $response);
					$response = str_replace('n:', '', $response);
					unset($parser2);
					$parser2 = new XMLParser($response);
					$parser2->Parse();
					$rootNd2 = $parser2->document->body[0]->placeproductorderresponse[0];
					$ResponseType = trim($rootNd2->responsetype[0]->tagData);
				}
				$num++;
			}
		} // end for
	}
	$leftDate++; // 검색 일자 더하기
} // end while
?>