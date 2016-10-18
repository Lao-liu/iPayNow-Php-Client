<?php
use Phalcon\Logger\Adapter\File;
use Phalcon\Mvc\User\Component;

/**
 * Project ~ MiniShop_Cli
 * FileName: iPayNow.php
 *
 * @author:  Laoliu <laoliu@lanmv.com>
 *
 * Date: 16/10/18 下午2:13
 * @property \Settlement settlement
 */
class iPayNow extends Component
{
    // 代付接口
    const TEST_API_AGENT_PAY_URL = 'https://dby.ipaynow.cn/agentpay/agentPay';
    const API_AGENT_PAY_URL      = 'https://saapi.ipaynow.cn/agentpay/agentPay';

    // 余额接口
    const TEST_API_ACCOUNT_BALANCE_QUERY_URL = 'https://dby.ipaynow.cn/agentpay/accountBalanceQuery';
    const API_ACCOUNT_BALANCE_QUERY_URL      = 'https://saapi.ipaynow.cn/agentpay/accountBalanceQuery';

    // 功能码
    const FUNCTION_CODE_AGENT_PAY             = 'AP04';
    const FUNCTION_CODE_ACCOUNT_BALANCE_QUERY = 'AP05';

    // 开发模式下用测试接口
    const DEV_MODEL = false;

    // 正式帐号
    const APP_ID  = '';
    const MD5_KEY = '';
    const DES_KEY = '';

    // 测试帐号
    const TEST_APP_ID  = '1459846530458585';
    const TEST_MD5_KEY = 'oAKsBv38CcLdnIKjshaoiY81erfotAC';
    const TEST_DES_KEY = 'eXKSUIIL6jkbfm8eR3iE2BmW';

    // 保持SESSION
    const COOKIE_FILE = APP_PATH . '/cache/cookies/payment.cookie';

    private $sendError  = false;
    private $settlement = null;

    /**
     * 自动处理代付请求
     *
     * 代付结构数据见 checkAgentPayData 方法
     *
     * @param $settlement
     *
     * @return bool
     */
    public function AutoAgentPayment($settlement)
    {

        if ($payNum = count($settlement)) {
            self::Logger()->info("有数据" . $payNum . "条待处理.");
            self::Logger()->info("当前应用编号: " . self::APP_ID);
            foreach ($settlement as $key => $payData) {

                // 查询当前余额, 如余额小于代付记录, 则发送通知.
                $balance = $this->QueryBalance();

                // 解密或提交失败
                if ($balance === false) {
                    return false;
                }

                // 如果账户余额不足1000元发送通知
                if (is_numeric($balance) && ($balance / 100) < $payData->settlement_money) {
                    self::Logger()->notice("现在支付电子账户余额不足 1000 元.");
                    // 微信提醒
                    // Send To WeChat
                    return false;
                }

                $this->settlement = $payData;
                self::Logger()->info("原始数据: " . json_encode($payData->toArray()));

                // 生成代付数据
                $data = $this->setAgentPayData($payData);
                self::Logger()->info("整理的上报数据: " . json_encode($data));
                self::Logger()->info("================= 开始执行代付 =================");

                // 执行代付操作
                $payed = $this->AgentPay($data);

                // 处理代付结果
                if ($payed) {
                    self::Logger()->info("================= 提交代付完毕 =================" . PHP_EOL . PHP_EOL . PHP_EOL);
                    // 处理代付结果
                } else {
                    self::Logger()->info("================= 提交代付失败 FAIL =================" . PHP_EOL . PHP_EOL . PHP_EOL);
                }

            }
        }

    }

    /**
     * 现在支付-代付
     *
     * @param $data
     *
     * @return bool
     */
    public function AgentPay($data)
    {

        if ($this->checkAgentPayData($data)) {

            $data = $this->getAgentPayData($data);
            self::Logger()->info("提交接口数据: " . json_encode($data));
            $response = $this->send(self::FUNCTION_CODE_AGENT_PAY, $data);
            self::Logger()->info("接口返回数据: " . json_encode($response));

            if ($response && !$this->sendError) {

                $result = $this->splitString($response);
                if ($result[ "code" ] == 1) {

                    $checkMd5 = self::md5($result[ "data" ]);
                    if ($checkMd5 === $result[ "sign" ]) {
                        parse_str($result[ "data" ], $params);

                        return $this->saveAgentPayResult($params);
                    } else {
                        self::Logger()->error("接口返回数据验证签名失败: " . json_encode($result) . PHP_EOL . "Sign: " . $checkMd5);

                        return false;
                    }

                } else {
                    self::Logger()->error("接口返回异常 --> Code[{$result["code"]}], Error: [{$result["data"]}]");

                    return false;
                }

            } else {

                echo $response;

            }

        } else {

            return false;

        }

    }

    /**
     * 现在支付-查询账户余额
     *
     * @return float
     */
    public function QueryBalance()
    {

        $data[ "mhtOrderNo" ]  = round(microtime(true) * 10000, 0);
        $data[ "mhtReqTime" ]  = date("YmdHis");
        $data[ "accountType" ] = 'AT01';

        ksort($data);

        self::Logger()->info("==================== 开始查询代付账户余额 ====================");
        $data     = $this->getQueryBalanceData($data);
        $response = $this->send(self::FUNCTION_CODE_ACCOUNT_BALANCE_QUERY, $data);
        self::Logger()->info("接口返回数据: " . json_encode($response));

        if ($response && !$this->sendError) {

            $result = $this->splitString($response);
            if ($result[ "code" ] == 1) {

                $checkMd5 = self::md5($result[ "data" ]);
                if ($checkMd5 === $result[ "sign" ]) {
                    parse_str($result[ "data" ], $params);
                    self::Logger()->info("==================== 查询代付账户余额结束 ====================" . PHP_EOL . PHP_EOL . PHP_EOL);

                    return intval($params[ "accountBalance" ]);
                } else {
                    self::Logger()->error("接口返回数据验证签名失败: " . json_encode($result) . PHP_EOL . "Sign: " . $checkMd5);

                    return false;
                }

            } else {
                self::Logger()->error("接口返回异常 --> Code[{$result["code"]}], Error: [{$result["data"]}]" . PHP_EOL . PHP_EOL . PHP_EOL);

                return false;
            }

        } else {

            echo $response;

        }

    }

    /**
     * 保存接口返回结果
     *
     * // 代付日志结构
     * CREATE TABLE `settlement_log` (
     *     `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
     *     `settlement_id` bigint(16) DEFAULT NULL COMMENT '代付数据编号',
     *     `funcode` char(4) DEFAULT NULL COMMENT '功能码',
     *     `app_id` varchar(64) DEFAULT NULL COMMENT '代付应用编号',
     *     `mht_order_no` varchar(64) DEFAULT NULL COMMENT '请求流水号',
     *     `now_pay_order_no` varchar(64) DEFAULT NULL COMMENT '现在支付流水号',
     *     `response_time` char(14) DEFAULT NULL COMMENT '响应时间',
     *     `response_code` char(4) DEFAULT NULL COMMENT '响应码',
     *     `response_msg` varchar(128) DEFAULT NULL COMMENT '响应信息',
     *     `trade_status` varchar(4) DEFAULT NULL COMMENT '交易状态',
     *     `accountBalance` decimal(10,2) DEFAULT NULL COMMENT '账户余额',
     *     PRIMARY KEY (`id`),
     *     KEY `SettlementId` (`settlement_id`)
     * ) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;
     *
     * @param $result
     *
     * @return bool
     */
    private function saveAgentPayResult($result)
    {
        // 保存返回数据
        $sl                   = new SettlementLog();
        $sl->settlement_id    = $this->settlement->id;
        $sl->funcode          = $result[ "funcode" ];
        $sl->app_id           = $result[ "appId" ];
        $sl->mht_order_no     = $result[ "mhtOrderNo" ];
        $sl->now_pay_order_no = $result[ "nowPayOrderNo" ];
        $sl->response_time    = $result[ "responseTime" ];
        $sl->response_code    = $result[ "responseCode" ];
        $sl->response_msg     = $result[ "responseMsg" ];
        $sl->trade_status     = $result[ "tradeStatus" ];

        self::Logger()->info("现在支付接口返回数据: " . json_encode($result));

        if ($sl->save()) {
            self::Logger()->info("接口返回数据保存完毕");

            return true;

        } else {

            $error = "";
            foreach ($sl->getMessages() as $message) {
                $error .= $message;
            }

            self::Logger()->error("记录现在支付接口返回数据是出错: " . $error . PHP_EOL . "返回数据: " . json_encode($result));

            return false;
        }
    }

    /**
     * 根据代付记录, 设置现在支付需要的代付结构数据
     *
     * // 代付结构
     * CREATE TABLE `settlement` (
     *     `id` bigint(16) NOT NULL AUTO_INCREMENT,
     *     `relate_id` int(11) DEFAULT NULL COMMENT '商家ID',
     *     `relate_type` tinyint(1) DEFAULT NULL,
     *     `bank_type` char(2) DEFAULT NULL COMMENT '账户类型',
     *     `bank` varchar(32) DEFAULT NULL COMMENT '开户银行',
     *     `bank_account_name` varchar(32) DEFAULT NULL COMMENT '账户名称',
     *     `bank_account` varchar(64) DEFAULT NULL COMMENT '银行帐号',
     *     `bank_account_union_no` varchar(64) DEFAULT NULL COMMENT '银行联行号',
     *     `pay_money` decimal(8,2) DEFAULT NULL COMMENT '支付金额',
     *     `remark` varchar(256) DEFAULT NULL COMMENT '备注信息',
     *     `created_at` datetime DEFAULT NULL COMMENT '创建时间',
     *     `commission_rate` decimal(8,5) DEFAULT NULL COMMENT '佣金比例',
     *     `settlement_money` decimal(8,2) DEFAULT NULL COMMENT '结算金额',
     *     `settlement_time` datetime DEFAULT NULL COMMENT '结算时间',
     *     `settlement_status` tinyint(1) DEFAULT NULL COMMENT '结算状态',
     *     PRIMARY KEY (`id`)
     * ) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8 ROW_FORMAT=COMPACT;
     *
     * @param Settlement $pay
     *
     * @return mixed
     */
    private function setAgentPayData(Settlement $pay)
    {
        $data[ "mhtOrderNo" ]   = $pay->id;
        $data[ "mhtReqTime" ]   = date("YmdHis");
        $data[ "payeeAccType" ] = $pay->bank_type;
        // 对公账户设置联行号
        if ($pay->bank_type === '01') {
            $data[ "payeeCardUnionNo" ] = $pay->bank_account_union_no;
        }
        $data[ "payeeName" ]    = $pay->bank_account_name;
        $data[ "payeeCardNo" ]  = $pay->bank_account;
        $data[ "mhtOrderAmt" ]  = round($pay->settlement_money * 100);
        $data[ "agentPayMemo" ] = str_replace(' ', '', $pay->remark);

        self::Logger()->info("整理后数据: " . json_encode($data));

        ksort($data);

        return $data;
    }

    /**
     * 支付数据检测
     *
     * @param $data
     *
     * @return bool
     */
    private function checkAgentPayData($data)
    {
        if (!isset($data[ "mhtOrderNo" ]) || empty($data[ "mhtOrderNo" ])) {
            self::Logger()->error("支付数据检测时出错: mhtOrderNo is empty.");

            return false;
        }
        if (!isset($data[ "mhtReqTime" ]) || empty($data[ "mhtReqTime" ])) {
            self::Logger()->error("支付数据检测时出错: mhtReqTime is empty.");

            return false;
        }
        if (!isset($data[ "payeeAccType" ]) || empty($data[ "payeeAccType" ])) {
            self::Logger()->error("支付数据检测时出错: payeeAccType is empty.");

            return false;
        }
        if (!isset($data[ "payeeName" ]) || empty($data[ "payeeName" ])) {
            self::Logger()->error("支付数据检测时出错: payeeName is empty.");

            return false;
        }
        if (!isset($data[ "payeeCardNo" ]) || empty($data[ "payeeCardNo" ])) {
            self::Logger()->error("支付数据检测时出错: payeeCardNo is empty.");

            return false;
        }
        if (!isset($data[ "mhtOrderAmt" ]) || empty($data[ "mhtOrderAmt" ])) {
            self::Logger()->error("支付数据检测时出错: mhtOrderAmt is empty.");

            return false;
        }
        if (!isset($data[ "agentPayMemo" ]) || empty($data[ "agentPayMemo" ])) {
            self::Logger()->error("支付数据检测时出错: agentPayMemo is empty.");

            return false;
        }
        self::Logger()->info("整理并校验后排序数据: " . json_encode($data));

        return true;
    }

    /**
     * 代付数据格式标准化
     *
     * @param $data
     *
     * @return string
     */
    private function getAgentPayData($data)
    {
        $data = urldecode(http_build_query($data));

        self::Logger()->info("URL参数格式数据: {$data}");

        $returnMess = "funcode=" . self::FUNCTION_CODE_AGENT_PAY . "&message=";
        $appInfo    = base64_encode("appId=" . self::APP_ID);
        $md5        = base64_encode(self::md5($data));
        $data       = $this->encrypt($data);
        $message    = $appInfo . "|" . $data . "|" . $md5;

        self::Logger()->info("MD5数据校验值: {$md5} " . "[" . base64_decode($md5) . "]");

        return $returnMess . urlencode($message);

    }

    /**
     * 查询余额格式标准化
     *
     * @param $data
     *
     * @return string
     */
    private function getQueryBalanceData($data)
    {
        $data = urldecode(http_build_query($data));

        self::Logger()->info("URL参数格式数据: {$data}");

        $returnMess = "funcode=" . self::FUNCTION_CODE_ACCOUNT_BALANCE_QUERY . "&message=";
        $appInfo    = base64_encode("appId=" . self::APP_ID);
        $md5        = base64_encode(self::md5($data));
        $data       = $this->encrypt($data);
        $message    = $appInfo . "|" . $data . "|" . $md5;

        self::Logger()->info("MD5数据校验值: {$md5} " . "[" . base64_decode($md5) . "]");

        return $returnMess . urlencode($message);

    }

    /**
     * 源数据加密方法
     *
     * @param array $plainText
     *
     * @return string
     */
    public function encrypt($plainText)
    {

        $ivSize          = mcrypt_get_iv_size(MCRYPT_TRIPLEDES, MCRYPT_MODE_ECB);
        $iv              = mcrypt_create_iv($ivSize, MCRYPT_RAND);
        $encryptedString = mcrypt_encrypt(MCRYPT_TRIPLEDES, self::DES_KEY, $plainText, MCRYPT_MODE_ECB, $iv);

        return base64_encode($encryptedString);
    }

    /**
     * 加密数据解密方法
     *
     * @param string $encrypted
     *
     * @return string
     */
    public function decrypt($encrypted)
    {
        $encrypted = base64_decode($encrypted);
        $ivSize    = mcrypt_get_iv_size(MCRYPT_TRIPLEDES, MCRYPT_MODE_ECB);
        $iv        = mcrypt_create_iv($ivSize, MCRYPT_RAND);
        $plainText = mcrypt_decrypt(MCRYPT_TRIPLEDES, self::DES_KEY, $encrypted, MCRYPT_MODE_ECB, $iv);

        return $plainText;
    }

    /**
     * 转换返回结果为数组
     *
     * @param $response
     *
     * @return array
     */
    private function splitString($response)
    {
        $response = urldecode($response);
        $response = str_replace(' ', '+', $response);
        $split    = explode("|", $response);

        return count($split) == 2 ? [
            "code" => $split[ 0 ],
            "data" => base64_decode($split[ 1 ])
        ] : [
            "code" => $split[ 0 ],
            "data" => rtrim($this->decrypt($split[ 1 ])),
            "sign" => base64_decode($split[ 2 ])
        ];
    }

    /**
     * MD5校验
     *
     * @param $data
     *
     * @return string
     */
    static private function md5($data)
    {
        self::Logger()->info("MD5校验串及结果: " . $data . self::MD5_KEY);

        return md5($data . self::MD5_KEY);
    }

    /**
     * 文件日志
     *
     * @return File
     */
    static private function Logger()
    {
        return new File(APP_PATH . "/cache/logs/iPayNow_" . date("Y-m-d") . ".log");
    }

    /**
     * HTTP Client for Api
     *
     * @param $funCode
     * @param $data
     *
     * @return string
     */
    private function send($funCode, $data)
    {
        if (self::DEV_MODEL) {
            $url = $funCode == self::FUNCTION_CODE_AGENT_PAY ? self::TEST_API_AGENT_PAY_URL : self::TEST_API_ACCOUNT_BALANCE_QUERY_URL;
        } else {
            $url = $funCode == self::FUNCTION_CODE_AGENT_PAY ? self::API_AGENT_PAY_URL : self::API_ACCOUNT_BALANCE_QUERY_URL;
        }

        self::Logger()->info("请求现在支付接口地址: " . $url);
        self::Logger()->info("向现在支付接口发送数据: " . $data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_COOKIEFILE, self::COOKIE_FILE);

        $response = curl_exec($ch);

        $error = "";
        if (curl_errno($ch)) {
            $error           = curl_error($ch);
            $this->sendError = true;
            self::Logger()->info("向现在支付接口发送数据时出错: " . $error);
        }
        curl_close($ch);

        return $error ? $error : $response;

    }

}