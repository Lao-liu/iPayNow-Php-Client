# 现在支付代付业务PHP客户端

## 简述

代码基于Phalcon实现, 不过代码的加密和解密以及通信方法, 均已经测试通过, 可直接使用。

## 使用

```
$ipaynow = new iPayNow();
$ipaynow->AutoAgentPayment('数组格式的代付数据');
```