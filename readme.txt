=== USS Upyun ===
Contributors: shenyanzhi
Donate link: https://qq52o.me/sponsor.html
Tags: USS, 又拍云, 对象存储, upyun, 云存储
Requires at least: 4.2
Tested up to: 5.5
Requires PHP: 5.6.0
Stable tag: 1.2.0
License: Apache 2.0
License URI: http://www.apache.org/licenses/LICENSE-2.0.html

使用又拍云云存储USS作为附件存储空间。（This is a plugin that uses UPYUN Storage Service for attachments remote saving.）

== Description ==

使用又拍云云存储USS作为附件存储空间。（This is a plugin that uses UPYUN Storage Service for attachments remote saving.）

* 依赖又拍云云存储USS服务：https://www.upyun.com/products/file-storage

## 插件特点

1. 可配置是否上传缩略图和是否保留本地备份
2. 本地删除可同步删除又拍云云存储USS中的文件
3. 支持又拍云云存储USS绑定的个性域名
4. 支持替换数据库中旧的资源链接地址
5. 支持又拍云云存储USS完整地域使用
6. 支持同步历史附件到又拍云云存储USS
7. 支持上传时自动重命名文件（MD5或时间戳+随机数两种方式）
8. 支持设置图片处理

插件更多详细介绍和安装：[https://github.com/sy-records/upyun-uss-wordpress](https://github.com/sy-records/upyun-uss-wordpress)

## 其他插件

腾讯云COS：[GitHub](https://github.com/sy-records/wordpress-qcloud-cos)，[WordPress Plugins](https://wordpress.org/plugins/sync-qcloud-cos)
华为云OBS：[GitHub](https://github.com/sy-records/huaweicloud-obs-wordpress)，[WordPress Plugins](https://wordpress.org/plugins/obs-huaweicloud)
七牛云KODO：[GitHub](https://github.com/sy-records/qiniu-kodo-wordpress)，[WordPress Plugins](https://wordpress.org/plugins/kodo-qiniu)
阿里云OSS：[GitHub](https://github.com/sy-records/aliyun-oss-wordpress)，[WordPress Plugins](https://wordpress.org/plugins/oss-aliyun)

## 作者博客

[沈唁志](https://qq52o.me "沈唁志")

QQ交流群：887595381

== Installation ==

1. Upload the folder `upyun-uss-wordpress` or `uss-upyun` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. That's all

== Screenshots ==

1. screenshot-1.png
2. screenshot-2.png

== Frequently Asked Questions ==

= 怎么替换文章中之前的旧资源地址链接 =

这个插件已经加上了替换数据库中之前的旧资源地址链接功能，只需要填好对应的链接即可

== Changelog ==

= 1.2.0=
* 优化同步文件逻辑
* 修复多站点上传原图失败，缩略图正常问题
* <del>升级sdk至3.5.0版本</del> 升不了 https://github.com/sy-records/upyun-uss-wordpress/commit/b899c35df6796fe282bf8c840a5e1244a6cffe30

= 1.1.3=
* 修复删除本地文件失败

= 1.1.2=
* 增加图片处理功能
* 替换数据库链接增加题图

= 1.1.1=
* 修复设置页面版本号问题
* 增加上传时自动重命名处理，支持MD5或时间戳+随机数两种方式

= 1.1.0 =
* 修复勾选不在本地保存图片后媒体库显示默认图片问题
* 修改删除为异步删除
* 修复本地文件夹为根目录时路径错误

= 1.0.1 =
* 修复勾选不在本地保存图片后媒体库显示默认图片问题

= 1.0.0 =
* First version
