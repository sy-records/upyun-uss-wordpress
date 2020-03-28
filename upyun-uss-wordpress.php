<?php
/*
Plugin Name: USS Upyun
Plugin URI: https://github.com/sy-records/upyun-uss-wordpress
Description: 使用又拍云云存储USS作为附件存储空间。（This is a plugin that uses UPYUN Storage Service for attachments remote saving.）
Version: 1.0.0
Author: 沈唁
Author URI: https://qq52o.me
License: Apache 2.0
*/

require_once 'sdk/vendor/autoload.php';

define('USS_VERSION', "1.0.0");
define('USS_BASEFOLDER', plugin_basename(dirname(__FILE__)));

use Upyun\Upyun;
use Upyun\Config;

// 初始化选项
register_activation_hook(__FILE__, 'uss_set_options');
// 初始化选项
function uss_set_options()
{
    $options = array(
        'bucket' => "",
        'OperatorName' => "",
        'OperatorPwd' => "",
        'nothumb' => "false", // 是否上传缩略图
        'nolocalsaving' => "false", // 是否保留本地备份
        'upload_url_path' => "", // URL前缀
    );
    add_option('uss_options', $options, '', 'yes');
}

function uss_get_client()
{
    $uss_opt = get_option('uss_options', true);
    $bucket = esc_attr($uss_opt['bucket']);
    $OperatorName = esc_attr($uss_opt['OperatorName']);
    $OperatorPwd = esc_attr($uss_opt['OperatorPwd']);
    $serviceConfig = new Config($bucket, $OperatorName, $OperatorPwd);
    return new Upyun($serviceConfig);
}

function uss_get_bucket_name()
{
    $uss_opt = get_option('uss_options', true);
    return esc_attr($uss_opt['bucket']);
}

/**
 * 上传函数
 *
 * @param  $object
 * @param  $file
 * @param  $opt
 * @return bool
 */
function uss_file_upload($object, $file)
{
    //如果文件不存在，直接返回false
    if (!@file_exists($file)) {
        return false;
    }
    $file = fopen($file, 'rb');
    if ($file) {
        $client = uss_get_client();
        $res = $client->write($object, $file);
//        var_dump($res);
    } else {
        return false;
    }
}

/**
 * 是否需要删除本地文件
 *
 * @return bool
 */
function uss_is_delete_local_file()
{
    $uss_options = get_option('uss_options', true);
    return (esc_attr($uss_options['nolocalsaving']) == 'true');
}

/**
 * 删除本地文件
 *
 * @param  $file
 * @return bool
 */
function uss_delete_local_file($file)
{
    try {
        //文件不存在
        if (!@file_exists($file)) {
            return true;
        }

        //删除文件
        if (!@unlink($file)) {
            return false;
        }

        return true;
    } catch (Exception $ex) {
        return false;
    }
}

/**
 * 删除uss中的文件
 * @param $file
 * @return bool
 */
function uss_delete_uss_file($file)
{
    $client = uss_get_client();
    $res = $client->delete($file);
//    var_dump($res);
}

/**
 * 上传附件（包括图片的原图）
 *
 * @param  $metadata
 * @return array()
 */
function uss_upload_attachments($metadata)
{
    //生成object在uss中的存储路径
    if (get_option('upload_path') == '.') {
        //如果含有“./”则去除之
        $metadata['file'] = str_replace("./", '', $metadata['file']);
    }
    $object = str_replace("\\", '/', $metadata['file']);
    $object = str_replace(get_home_path(), '', $object);

    //在本地的存储路径
    $file = get_home_path() . $object; //向上兼容，较早的WordPress版本上$metadata['file']存放的是相对路径

    //执行上传操作
    uss_file_upload('/' . $object, $file);

    //如果不在本地保存，则删除本地文件
    if (uss_is_delete_local_file()) {
        uss_delete_local_file($file);
    }
    return $metadata;
}

//避免上传插件/主题时出现同步到uss的情况
if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
    add_filter('wp_handle_upload', 'uss_upload_attachments', 50);
}

/**
 * 上传图片的缩略图
 */
function uss_upload_thumbs($metadata)
{
    //上传所有缩略图
    if (isset($metadata['sizes']) && count($metadata['sizes']) > 0) {
        //获取uss插件的配置信息
        $uss_options = get_option('uss_options', true);
        //是否需要上传缩略图
        $nothumb = (esc_attr($uss_options['nothumb']) == 'true');
        //是否需要删除本地文件
        $is_delete_local_file = (esc_attr($uss_options['nolocalsaving']) == 'true');
        //如果禁止上传缩略图，就不用继续执行了
        if ($nothumb) {
            return $metadata;
        }
        //获取上传路径
        $wp_uploads = wp_upload_dir();
        $basedir = $wp_uploads['basedir'];
        $file_dir = $metadata['file'];
        //得到本地文件夹和远端文件夹
        $file_path = $basedir . '/' . dirname($file_dir) . '/';
        if (get_option('upload_path') == '.') {
            $file_path = str_replace("\\", '/', $file_path);
            $file_path = str_replace(get_home_path() . "./", '', $file_path);
        } else {
            $file_path = str_replace("\\", '/', $file_path);
        }

        $object_path = str_replace(get_home_path(), '', $file_path);

        //there may be duplicated filenames,so ....
        foreach ($metadata['sizes'] as $val) {
            //生成object在uss中的存储路径
            $object = '/' . $object_path . $val['file'];
            //生成本地存储路径
            $file = $file_path . $val['file'];

            //执行上传操作
            uss_file_upload($object, $file);

            //如果不在本地保存，则删除
            if ($is_delete_local_file) {
                uss_delete_local_file($file);
            }
        }
    }
    return $metadata;
}

//避免上传插件/主题时出现同步到uss的情况
if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
    add_filter('wp_generate_attachment_metadata', 'uss_upload_thumbs', 100);
}

/**
 * 删除远端文件，删除文件时触发
 * @param $post_id
 */
function uss_delete_remote_attachment($post_id)
{
    $meta = wp_get_attachment_metadata($post_id);

    $uss_options = get_option('uss_options', true);

    if (isset($meta['file'])) {
        // meta['file']的格式为 "2020/01/wp-bg.png"
        $upload_path = get_option('upload_path');
        if ($upload_path == '') {
            $upload_path = 'wp-content/uploads';
        }
        $file_path = $upload_path . '/' . $meta['file'];
        uss_delete_uss_file(str_replace("\\", '/', $file_path));
        $is_nothumb = (esc_attr($uss_options['nothumb']) == 'false');
        if ($is_nothumb) {
            // 删除缩略图
            if (isset($meta['sizes']) && count($meta['sizes']) > 0) {
                foreach ($meta['sizes'] as $val) {
                    $size_file = dirname($file_path) . '/' . $val['file'];
                    uss_delete_uss_file(str_replace("\\", '/', $size_file));
                }
            }
        }
    }
}

add_action('delete_attachment', 'uss_delete_remote_attachment');

// 当upload_path为根目录时，需要移除URL中出现的“绝对路径”
function uss_modefiy_img_url($url, $post_id)
{
    $home_path = str_replace(array('/', '\\'), array('', ''), get_home_path());
    $url = str_replace($home_path, '', $url);
    return $url;
}

if (get_option('upload_path') == '.') {
    add_filter('wp_get_attachment_url', 'uss_modefiy_img_url', 30, 2);
}

function uss_function_each(&$array)
{
    $res = array();
    $key = key($array);
    if ($key !== null) {
        next($array);
        $res[1] = $res['value'] = $array[$key];
        $res[0] = $res['key'] = $key;
    } else {
        $res = false;
    }
    return $res;
}

function uss_read_dir_queue($dir)
{
    if (isset($dir)) {
        $files = array();
        $queue = array($dir);
        while ($data = uss_function_each($queue)) {
            $path = $data['value'];
            if (is_dir($path) && $handle = opendir($path)) {
                while ($file = readdir($handle)) {
                    if ($file == '.' || $file == '..') {
                        continue;
                    }
                    $files[] = $real_path = $path . '/' . $file;
                    if (is_dir($real_path)) {
                        $queue[] = $real_path;
                    }
                    //echo explode(get_option('upload_path'),$path)[1];
                }
            }
            closedir($handle);
        }
        $i = '';
        foreach ($files as $v) {
            $i++;
            if (!is_dir($v)) {
                $dd[$i]['j'] = $v;
                $dd[$i]['x'] = '/' . get_option('upload_path') . explode(get_option('upload_path'), $v)[1];
            }
        }
    } else {
        $dd = '';
    }
    return $dd;
}

// 在插件列表页添加设置按钮
function uss_plugin_action_links($links, $file)
{
    if ($file == plugin_basename(dirname(__FILE__) . '/upyun-uss-wordpress.php')) {
        $links[] = '<a href="options-general.php?page=' . USS_BASEFOLDER . '/upyun-uss-wordpress.php">设置</a>';
        $links[] = '<a href="https://qq52o.me/sponsor.html" target="_blank">赞赏</a>';
    }
    return $links;
}

add_filter('plugin_action_links', 'uss_plugin_action_links', 10, 2);

// 在导航栏“设置”中添加条目
function uss_add_setting_page()
{
    add_options_page('又拍云USS设置', '又拍云USS设置', 'manage_options', __FILE__, 'uss_setting_page');
}

add_action('admin_menu', 'uss_add_setting_page');

// 插件设置页面
function uss_setting_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient privileges!');
    }
    $options = array();
    if (!empty($_POST) and $_POST['type'] == 'uss_set') {
        $options['bucket'] = isset($_POST['bucket']) ? sanitize_text_field($_POST['bucket']) : '';
        $options['OperatorName'] = isset($_POST['OperatorName']) ? sanitize_text_field($_POST['OperatorName']) : '';
        $options['OperatorPwd'] = isset($_POST['OperatorPwd']) ? sanitize_text_field($_POST['OperatorPwd']) : '';
        $options['nothumb'] = isset($_POST['nothumb']) ? 'true' : 'false';
        $options['nolocalsaving'] = isset($_POST['nolocalsaving']) ? 'true' : 'false';
        //仅用于插件卸载时比较使用
        $options['upload_url_path'] = isset($_POST['upload_url_path']) ? sanitize_text_field(
            stripslashes($_POST['upload_url_path'])
        ) : '';
    }

    if (!empty($_POST) and $_POST['type'] == 'upyun_uss_all') {
        $synv = uss_read_dir_queue(get_home_path() . get_option('upload_path'));
        $i = 0;
        foreach ($synv as $k) {
            $i++;
            uss_file_upload($k['x'], $k['j']);
        }
        echo '<div class="updated"><p><strong>本次操作成功同步' . $i . '个文件</strong></p></div>';
    }

    // 替换数据库链接
    if (!empty($_POST) and $_POST['type'] == 'upyun_uss_replace') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'posts';
        $oldurl = esc_url_raw($_POST['old_url']);
        $newurl = esc_url_raw($_POST['new_url']);
        $result = $wpdb->query("UPDATE $table_name SET post_content = REPLACE( post_content, '$oldurl', '$newurl') ");

        echo '<div class="updated"><p><strong>替换成功！共批量执行' . $result . '条！</strong></p></div>';
    }

    // 若$options不为空数组，则更新数据
    if ($options !== array()) {
        //更新数据库
        update_option('uss_options', $options);

        $upload_path = sanitize_text_field(trim(stripslashes($_POST['upload_path']), '/'));
        $upload_path = ($upload_path == '') ? ('wp-content/uploads') : ($upload_path);
        update_option('upload_path', $upload_path);
        $upload_url_path = sanitize_text_field(trim(stripslashes($_POST['upload_url_path']), '/'));
        update_option('upload_url_path', $upload_url_path);
        echo '<div class="updated"><p><strong>设置已保存！</strong></p></div>';
    }

    $uss_options = get_option('uss_options', true);
    $upload_path = get_option('upload_path');
    $upload_url_path = get_option('upload_url_path');

    $uss_bucket = esc_attr($uss_options['bucket']);
    $uss_OperatorName = esc_attr($uss_options['OperatorName']);
    $uss_OperatorPwd = esc_attr($uss_options['OperatorPwd']);

    $uss_nothumb = esc_attr($uss_options['nothumb']);
    $uss_nothumb = ($uss_nothumb == 'true');

    $uss_nolocalsaving = esc_attr($uss_options['nolocalsaving']);
    $uss_nolocalsaving = ($uss_nolocalsaving == 'true');

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    ?>
    <div class="wrap" style="margin: 10px;">
        <h1>又拍云 USS 设置 <span style="font-size: 13px;">当前版本：<?php echo USS_VERSION; ?></span></h1>
        <p>优惠促销： <a href="https://go.qq52o.me/a/upyun" target="_blank">点我注册并完成实名认证，赠送 61 元免费代金券</a></p>
        <p>如果觉得此插件对你有所帮助，不妨到 <a href="https://github.com/sy-records/upyun-uss-wordpress" target="_blank">Github</a> 上点个<code>Star</code>，<code>Watch</code>关注更新；
        </p>
        <hr/>
        <form name="form1" method="post" action="<?php echo wp_nonce_url(
            './options-general.php?page=' . USS_BASEFOLDER . '/upyun-uss-wordpress.php'
        ); ?>">
            <table class="form-table">
                <tr>
                    <th>
                        <legend>服务名称</legend>
                    </th>
                    <td>
                        <input type="text" name="bucket" value="<?php echo $uss_bucket; ?>" size="50" placeholder="请填写服务名称"/>
                        <p>请先访问 <a href="https://console.upyun.com/services/create/file/" target="_blank">又拍云控制台</a> 创建<code>云存储服务</code>，再填写以上内容。</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>操作员</legend>
                    </th>
                    <td><input type="text" name="OperatorName" value="<?php echo $uss_OperatorName; ?>" size="50" placeholder="OperatorName"/></td>
                </tr>
                <tr>
                    <th>
                        <legend>密码</legend>
                    </th>
                    <td>
                        <input type="text" name="OperatorPwd" value="<?php echo $uss_OperatorPwd; ?>" size="50" placeholder="OperatorPwd"/>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>不上传缩略图</legend>
                    </th>
                    <td>
                        <input type="checkbox" name="nothumb" <?php if ($uss_nothumb) { echo 'checked="checked"'; } ?> />
                        <p>建议不勾选</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>不在本地保留备份</legend>
                    </th>
                    <td>
                        <input type="checkbox" name="nolocalsaving" <?php if ($uss_nolocalsaving) { echo 'checked="checked"'; } ?> />
                        <p>建议不勾选</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>本地文件夹</legend>
                    </th>
                    <td>
                        <input type="text" name="upload_path" value="<?php echo $upload_path; ?>" size="50" placeholder="请输入上传文件夹"/>
                        <p>附件在服务器上的存储位置，例如： <code>wp-content/uploads</code> （注意不要以“/”开头和结尾），根目录请输入<code>.</code>。</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>URL前缀</legend>
                    </th>
                    <td>
                        <input type="text" name="upload_url_path" value="<?php echo $upload_url_path; ?>" size="50" placeholder="请输入URL前缀"/>

                        <p><b>注意：</b></p>

                        <p>1）URL前缀的格式为 <code><?php echo $protocol; ?>{加速域名}/{本地文件夹}</code>
                            ，“本地文件夹”务必与上面保持一致（结尾无<code>/</code> ），或者“本地文件夹”为 <code>.</code> 时
                            <code><?php echo $protocol; ?>{加速域名}</code> 。
                        </p>

                        <p>2）URL前缀中的协议头<code>http://</code>和<code>https://</code>请根据实际情况填写，提示的协议头默认和源站相同。</p>
                        <p>3）操作员需要有<code>可读取</code>、<code>可写入</code>、<code>可删除</code>权限。</p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>保存/更新选项</legend>
                    </th>
                    <td><input type="submit" name="submit" class="button button-primary" value="保存更改"/></td>
                </tr>
            </table>
            <input type="hidden" name="type" value="uss_set">
        </form>
        <form name="form2" method="post" action="<?php echo wp_nonce_url(
            './options-general.php?page=' . USS_BASEFOLDER . '/upyun-uss-wordpress.php'
        ); ?>">
            <table class="form-table">
                <tr>
                    <th>
                        <legend>同步历史附件</legend>
                    </th>
                    <input type="hidden" name="type" value="upyun_uss_all">
                    <td>
                        <input type="submit" name="submit" class="button button-secondary" value="开始同步"/>
                        <p><b>注意：如果是首次同步，执行时间将会十分十分长（根据你的历史附件数量），有可能会因执行时间过长，页面显示超时或者报错。<br> 所以，建议那些几千上万附件的大神们，考虑官方的 <a target="_blank" rel="nofollow" href="https://help.upyun.com/knowledge-base/developer_tools/">同步工具</a></b></p>
                    </td>
                </tr>
            </table>
        </form>
        <hr>
        <form name="form3" method="post" action="<?php echo wp_nonce_url(
            './options-general.php?page=' . USS_BASEFOLDER . '/upyun-uss-wordpress.php'
        ); ?>">
            <table class="form-table">
                <tr>
                    <th>
                        <legend>数据库原链接替换</legend>
                    </th>
                    <td>
                        <input type="text" name="old_url" size="50" placeholder="请输入要替换的旧域名"/>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend></legend>
                    </th>
                    <td>
                        <input type="text" name="new_url" size="50" placeholder="请输入要替换的新域名"/>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend></legend>
                    </th>
                    <input type="hidden" name="type" value="upyun_uss_replace">
                    <td>
                        <input type="submit" name="submit" class="button button-secondary" value="开始替换"/>
                        <p><b>注意：如果是首次替换，请注意备份！此功能只限于替换文章中使用的资源链接</b></p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
    <?php
}

?>
