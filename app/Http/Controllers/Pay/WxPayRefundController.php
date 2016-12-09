<?php
namespace App\Http\Controllers\Pay;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests;
use DB;
use Redirect;

/**
 * 微信支付退款实现类
 */
 
class WxPayRefundController extends Controller {
	
	public function __construct(){
		header("Content-type:text/html;charset=utf-8");
		//微信退款API
		require_once(app_path().'/Library/Wxpay/lib/WxPayConfig.php');
		require_once(app_path().'/Library/Wxpay/lib/WxPayData.php');
		require_once(app_path().'/Library/Wxpay/lib/WxPayException.php');
		require_once(app_path().'/Library/Wxpay/lib/WxPayNotify.php');
		require_once(app_path().'/Library/Wxpay/lib/WxPayApi.php');
		require_once(app_path().'/Library/Wxpay/log.php');
		
		date_default_timezone_set('Asia/Shanghai');
    }
	
	public $data = null;
	
	function wxRefund($info){
		
		$input = new \WxPayRefund();
		$info["out_trade_no"] || $input->SetOut_trade_no($info["out_trade_no"]);
		$info["transaction_id"] || $input->SetTransaction_id($info["transaction_id"]);
		$input->SetTotal_fee($info["total_fee"]);
		$input->SetRefund_fee($info["refund_fee"]);
		$input->SetOut_refund_no(date("YmdHis"));
		$input->SetOp_user_id(\WxPayConfig::MCHID);
		$info = \WxPayApi::refund($input);
		
		$parameters = json_encode($info);
		return $parameters;
	}
	
	/**
	 * 
	 * 通过跳转获取用户的openid，跳转流程如下：
	 * 1、设置自己需要调回的url及其其他参数，跳转到微信服务器https://open.weixin.qq.com/connect/oauth2/authorize
	 * 2、微信服务处理完成之后会跳转回用户redirect_uri地址，此时会带上一些参数，如：code
	 * 
	 * @return 用户的openid
	 */
	public function GetOpenid(){

		if (!isset($_GET['code'])){
			//触发微信返回code码
			$baseUrl = urlencode('http://'.$_SERVER['HTTP_HOST'].'/wxauth');
			$url = $this->__CreateOauthUrlForCode($baseUrl);
			Header("Location: $url");
			exit();
		}else{
		    $code = $_GET['code'];
			$openid = $this->getOpenidFromMp($code);
			return $openid;
		}
	}
	
	/**
	 * 
	 * 获取jsapi支付的参数
	 * @param array $UnifiedOrderResult 统一支付接口返回的数据
	 * @throws WxPayException
	 * 
	 * @return json数据，可直接填入js函数作为参数
	 */
	public function GetJsApiParameters($UnifiedOrderResult){
		if(!array_key_exists("appid", $UnifiedOrderResult)|| !array_key_exists("prepay_id", $UnifiedOrderResult)|| $UnifiedOrderResult['prepay_id'] == ""){
			throw new \WxPayException("参数错误");
		}
		$jsapi = new \WxPayJsApiPay();
		$jsapi->SetAppid($UnifiedOrderResult["appid"]);
		$timeStamp = time();
		$jsapi->SetTimeStamp("$timeStamp");
		$jsapi->SetNonceStr(\WxPayApi::getNonceStr());
		$jsapi->SetPackage("prepay_id=" . $UnifiedOrderResult['prepay_id']);
		$jsapi->SetSignType("MD5");
		$jsapi->SetPaySign($jsapi->MakeSign());
		$parameters = json_encode($jsapi->GetValues());
		return $parameters;
	}
	
	/**
	 * 
	 * 通过code从工作平台获取openid机器access_token
	 * @param string $code 微信跳转回来带上的code
	 * 
	 * @return openid
	 */
	public function GetOpenidFromMp($code){
		$url = $this->__CreateOauthUrlForOpenid($code);
		//初始化curl
		$ch = curl_init();
		//设置超时
		//curl_setopt($ch, CURLOPT_TIMEOUT, $this->curl_timeout);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,FALSE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		if(\WxPayConfig::CURL_PROXY_HOST != "0.0.0.0" 
			&& \WxPayConfig::CURL_PROXY_PORT != 0){
			curl_setopt($ch,CURLOPT_PROXY, \WxPayConfig::CURL_PROXY_HOST);
			curl_setopt($ch,CURLOPT_PROXYPORT, \WxPayConfig::CURL_PROXY_PORT);
		}
		//运行curl，结果以json形式返回
		$res = curl_exec($ch);
		curl_close($ch);
		//取出openid
		$data = json_decode($res,true);
		$this->data = $data;
		$openid = $data['openid'];
		return $openid;
	}
	
	/**
	 * 
	 * 拼接签名字符串
	 * @param array $urlObj
	 * 
	 * @return 返回已经拼接好的字符串
	 */
	private function ToUrlParams($urlObj){
		$buff = "";
		foreach ($urlObj as $k => $v)
		{
			if($k != "sign"){
				$buff .= $k . "=" . $v . "&";
			}
		}
		
		$buff = trim($buff, "&");
		return $buff;
	}
	
	/**
	 * 
	 * 获取地址js参数
	 * 
	 * @return 获取共享收货地址js函数需要的参数，json格式可以直接做参数使用
	 */
	public function GetEditAddressParameters(){	
		$getData = $this->data;
		$data = array();
		$data["appid"] = \WxPayConfig::APPID;
		$data["url"] = "http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		$time = time();
		$data["timestamp"] = "$time";
		$data["noncestr"] = "1234568";
		$data["accesstoken"] = $getData["access_token"];
		ksort($data);
		$params = $this->ToUrlParams($data);
		$addrSign = sha1($params);
		
		$afterData = array(
			"addrSign" => $addrSign,
			"signType" => "sha1",
			"scope" => "jsapi_address",
			"appId" => \WxPayConfig::APPID,
			"timeStamp" => $data["timestamp"],
			"nonceStr" => $data["noncestr"]
		);
		$parameters = json_encode($afterData);
		return $parameters;
	}
	
	/**
	 * 
	 * 构造获取code的url连接
	 * @param string $redirectUrl 微信服务器回跳的url，需要url编码
	 * 
	 * @return 返回构造好的url
	 */
	private function __CreateOauthUrlForCode($redirectUrl){
		$urlObj["appid"] = \WxPayConfig::APPID;
		$urlObj["redirect_uri"] = "$redirectUrl";
		$urlObj["response_type"] = "code";
		$urlObj["scope"] = "snsapi_base";
		$urlObj["state"] = "STATE"."#wechat_redirect";
		$bizString = $this->ToUrlParams($urlObj);
		return "https://open.weixin.qq.com/connect/oauth2/authorize?".$bizString;
	}
	
	/**
	 * 
	 * 构造获取open和access_toke的url地址
	 * @param string $code，微信跳转带回的code
	 * 
	 * @return 请求的url
	 */
	private function __CreateOauthUrlForOpenid($code){
		$urlObj["appid"] = \WxPayConfig::APPID;
		$urlObj["secret"] = \WxPayConfig::APPSECRET;
		$urlObj["code"] = $code;
		$urlObj["grant_type"] = "authorization_code";
		$bizString = $this->ToUrlParams($urlObj);
		return "https://api.weixin.qq.com/sns/oauth2/access_token?".$bizString;
	}
}