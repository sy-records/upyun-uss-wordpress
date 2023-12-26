<?php
/*
Plugin Name: USS Upyun
Plugin URI: https://github.com/sy-records/upyun-uss-wordpress
Description: 使用又拍云云存储USS作为附件存储空间。（This is a plugin that uses UPYUN Storage Service for attachments remote saving.）
Version: 1.4.0
Author: 沈唁
Author URI: https://qq52o.me
License: Apache 2.0
*/

require_once 'sdk/vendor/autoload.php';

define('USS_VERSION', '1.4.0');
define('USS_BASEFOLDER', plugin_basename(dirname(__FILE__)));

use Upyun\Upyun;
use Upyun\Config;

if (!function_exists('get_home_path')) {
    require_once(ABSPATH . 'wp-admin/includes/file.php');
}

// 初始化选项
register_activation_hook(__FILE__, 'uss_set_options');
// 初始化选项
function uss_set_options()
{
    $options = [
        'bucket' => '',
        'OperatorName' => '',
        'OperatorPwd' => '',
        'nothumb' => 'false', // 是否上传缩略图
        'nolocalsaving' => 'false', // 是否保留本地备份
        'upload_url_path' => '', // URL前缀
        'update_file_name' => 'false', // 是否重命名文件名
        'image_process' => '', // 图片处理 https://console.upyun.com/services/process/
    ];
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

/**
 * 上传
 *
 * @param $object
 * @param $file
 * @param false $no_local_file
 * @return false|void
 * @throws Exception
 */
function uss_file_upload($object, $file, $no_local_file = false)
{
    //如果文件不存在，直接返回false
    if (!@file_exists($file)) {
        return false;
    }

    $file_resource = fopen($file, 'rb');
    if (!$file_resource) {
        return false;
    }

    $client = uss_get_client();
    $client->write($object, $file_resource);
    if (is_resource($file_resource)) {
        fclose($file);
    }
    if ($no_local_file) {
        uss_delete_local_file($file);
    }
}

function uss_sanitize_file_name($filename)
{
    $uss_options = get_option('uss_options');
    switch ($uss_options['update_file_name']) {
        case 'md5':
            return  md5($filename) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        case 'time':
            return date('YmdHis', current_time('timestamp')) . mt_rand(100, 999) . '.' . pathinfo($filename, PATHINFO_EXTENSION);
        default:
            return $filename;
    }
}

add_filter('sanitize_file_name', 'uss_sanitize_file_name', 10, 1);

/**
 * 是否需要删除本地文件
 *
 * @return bool
 */
function uss_is_delete_local_file()
{
    $uss_options = get_option('uss_options', true);
    return esc_attr($uss_options['nolocalsaving']) == 'true';
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
    } catch (\Exception $ex) {
        return false;
    }
}

/**
 * 删除uss中的文件
 * @param $file
 * @return void
 */
function uss_delete_uss_file($file)
{
    $client = uss_get_client();
    $client->delete($file, true);
}

/**
 * 批量删除uss中的文件
 *
 * @param array $files
 * @return void
 */
function uss_delete_uss_files($files)
{
    $client = uss_get_client();
    foreach ($files as $file) {
        $client->delete(str_replace(["\\", './'], ['/', ''], $file), true);
    }
}

/**
 * 上传附件（包括图片的原图）
 *
 * @param array $metadata
 * @return array
 */
function uss_upload_attachments($metadata)
{
    $mime_types = get_allowed_mime_types();
    $image_mime_types = [
        $mime_types['jpg|jpeg|jpe'],
        $mime_types['gif'],
        $mime_types['png'],
        $mime_types['bmp'],
        $mime_types['tiff|tif'],
        $mime_types['webp'],
        $mime_types['ico'],
        $mime_types['heic'],
    ];

    // 例如mp4等格式 上传后根据配置选择是否删除 删除后媒体库会显示默认图片 点开内容是正常的
    // 图片在缩略图处理
    if (!in_array($metadata['type'], $image_mime_types)) {
        //生成object在uss中的存储路径
        if (get_option('upload_path') == '.') {
            $metadata['file'] = str_replace("./", '', $metadata['file']);
        }
        $object = str_replace("\\", '/', $metadata['file']);
        $home_path = get_home_path();
        $object = str_replace($home_path, '', $object);

        //在本地的存储路径
        $file = $home_path . $object; //向上兼容，较早的WordPress版本上$metadata['file']存放的是相对路径

        //执行上传操作
        uss_file_upload('/' . $object, $file, uss_is_delete_local_file());
    }

    return $metadata;
}

/**
 * 上传图片的缩略图
 */
function uss_upload_thumbs($metadata)
{
    if (empty($metadata['file'])) {
        return $metadata;
    }

    //获取上传路径
    $wp_uploads = wp_upload_dir();
    $basedir = $wp_uploads['basedir'];
    $upload_path = get_option('upload_path');
    //获取uss插件的配置信息
    $uss_options = get_option('uss_options', true);
    $no_local_file = esc_attr($uss_options['nolocalsaving']) == 'true';
    $no_thumb = esc_attr($uss_options['nothumb']) == 'true';

    // Maybe there is a problem with the old version
    // Fix multi-site problems
    $file = $basedir . '/' . $metadata['file'];
    if ($upload_path != '.') {
        $path_array = explode($upload_path, $file);
        if (isset($path_array[1]) && !empty($path_array[1])) {
            $object = '/' . $upload_path . $path_array[1];
        }
    } else {
        $object = '/' . $metadata['file'];
        $file = str_replace('./', '', $file);
    }

    uss_file_upload($object, $file, $no_local_file);

    //得到本地文件夹和远端文件夹
    $dirname = dirname($metadata['file']);
    $file_path = $dirname != '.' ? "{$basedir}/{$dirname}/" : "{$basedir}/";
    $file_path = str_replace("\\", '/', $file_path);
    if ($upload_path == '.') {
        $file_path = str_replace('./', '', $file_path);
    }
    $object_path = str_replace(get_home_path(), '', $file_path);

    if (!empty($metadata['original_image'])) {
        uss_file_upload("/{$object_path}{$metadata['original_image']}", "{$file_path}{$metadata['original_image']}", $no_local_file);
    }

    //如果禁止上传缩略图，就不用继续执行了
    if ($no_thumb) {
        return $metadata;
    }

    //上传所有缩略图
    if (!empty($metadata['sizes'])) {
        foreach ($metadata['sizes'] as $val) {
            $object = '/' . $object_path . $val['file'];
            $file = $file_path . $val['file'];

            uss_file_upload($object, $file, $no_local_file);
        }
    }

    return $metadata;
}

/**
 * @param $override
 * @return mixed
 */
function uss_save_image_editor_file($override)
{
    add_filter('wp_update_attachment_metadata', 'uss_image_editor_file_do');
    return $override;
}

/**
 * @param $metadata
 * @return mixed
 */
function uss_image_editor_file_do($metadata)
{
    return uss_upload_thumbs($metadata);
}

//避免上传插件/主题时出现同步到uss的情况
if (substr_count($_SERVER['REQUEST_URI'], '/update.php') <= 0) {
    add_filter('wp_handle_upload', 'uss_upload_attachments', 50);
    add_filter('wp_generate_attachment_metadata', 'uss_upload_thumbs', 100);
    add_filter('wp_save_image_editor_file', 'uss_save_image_editor_file', 101);
}

/**
 * 删除远端文件，删除文件时触发
 * @param $post_id
 */
function uss_delete_remote_attachment($post_id)
{
    $meta = wp_get_attachment_metadata($post_id);
    $upload_path = get_option('upload_path');
    if ($upload_path == '') {
        $upload_path = 'wp-content/uploads';
    }

    if (!empty($meta['file'])) {
        $deleteObjects = [];
        // meta['file']的格式为 "2020/01/wp-bg.png"
        $file_path = $upload_path . '/' . $meta['file'];
        $deleteObjects[] = $file_path;

        $dirname = dirname($file_path) . '/';

        // 超大图原图
        if (!empty($meta['original_image'])) {
            $deleteObjects[] = $dirname . $meta['original_image'];
        }

        // 删除缩略图
        if (!empty($meta['sizes'])) {
            foreach ($meta['sizes'] as $val) {
                $deleteObjects[] = $dirname . $val['file'];
            }
        }

        $backup_sizes = get_post_meta($post_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backup_sizes)) {
            foreach ($backup_sizes as $size) {
                $deleteObjects[] = $dirname . $size['file'];
            }
        }

        uss_delete_uss_files($deleteObjects);
    } else {
        // 获取链接删除
        $link = wp_get_attachment_url($post_id);
        if ($link) {
            if ($upload_path != '.') {
                $file_info = explode($upload_path, $link);
                if (isset($file_info[1])) {
                    uss_delete_uss_file($upload_path . $file_info[1]);
                }
            } else {
                $uss_options = get_option('uss_options', true);
                $uss_upload_url = esc_attr($uss_options['upload_url_path']);
                $file_info = explode($uss_upload_url, $link);
                if (isset($file_info[1])) {
                    uss_delete_uss_file($file_info[1]);
                }
            }
        }
    }
}

add_action('delete_attachment', 'uss_delete_remote_attachment');

// 当upload_path为根目录时，需要移除URL中出现的“绝对路径”
function uss_modefiy_img_url($url, $post_id)
{
    // 移除 ./ 和 项目根路径
    return str_replace(['./', get_home_path()], '', $url);
}

if (get_option('upload_path') == '.') {
    add_filter('wp_get_attachment_url', 'uss_modefiy_img_url', 30, 2);
}

/**
 * @param string $homePath
 * @param string $uploadPath
 * @return array
 */
function uss_read_dir_queue($homePath, $uploadPath)
{
    $dir = $homePath . $uploadPath;
    $dirsToProcess = new SplQueue();
    $dirsToProcess->enqueue([$dir, '']);
    $foundFiles = [];

    while (!$dirsToProcess->isEmpty()) {
        list($currentDir, $relativeDir) = $dirsToProcess->dequeue();

        foreach (new DirectoryIterator($currentDir) as $fileInfo) {
            if ($fileInfo->isDot()) continue;

            $filepath = $fileInfo->getRealPath();

            // Compute the relative path of the file/directory with respect to upload path
            $currentRelativeDir = "{$relativeDir}/{$fileInfo->getFilename()}";

            if ($fileInfo->isDir()) {
                $dirsToProcess->enqueue([$filepath, $currentRelativeDir]);
            } else {
                // Add file path and key to the result array
                $foundFiles[] = [
                    'filepath' => $filepath,
                    'key' => '/' . $uploadPath . $currentRelativeDir
                ];
            }
        }
    }

    return $foundFiles;
}

add_filter('the_content', 'uss_setting_content_img_process');
add_filter('post_thumbnail_html', 'uss_setting_post_thumbnail_img_process', 10, 3);
add_filter('wp_calculate_image_srcset', 'uss_custom_image_srcset', 10, 5);

function uss_custom_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id)
{
    $option = get_option('uss_options');
    $style = !empty($option['image_process']) ? esc_attr($option['image_process']) : '';
    $upload_url_path = esc_attr($option['upload_url_path']);
    if (empty($style)) {
        return $sources;
    }

    foreach ($sources as $index => $source) {
        if (strpos($source['url'], $upload_url_path) !== false && strpos($source['url'], $style) === false) {
            $sources[$index]['url'] .= $style;
        }
    }

    return $sources;
}

function uss_setting_content_img_process($content)
{
    $option = get_option('uss_options');
    $style = esc_attr($option['image_process']);
    $upload_url_path = esc_attr($option['upload_url_path']);
    if (!empty($style)) {
        preg_match_all('/<img.*?(?: |\\t|\\r|\\n)?src=[\'"]?(.+?)[\'"]?(?:(?: |\\t|\\r|\\n)+.*?)?>/sim', $content, $images);
        if (!empty($images) && isset($images[1])) {
            $images[1] = array_unique($images[1]);
            foreach ($images[1] as $item) {
                if (strpos($item, $upload_url_path) !== false && strpos($item, $style) === false) {
                    $content = str_replace($item, $item . $style, $content);
                }
            }

            $content = str_replace($style . $style, $style, $content);
        }
    }

    return $content;
}

function uss_setting_post_thumbnail_img_process($html, $post_id, $post_image_id)
{
    $option = get_option('uss_options');
    $style = esc_attr($option['image_process']);
    $upload_url_path = esc_attr($option['upload_url_path']);
    if (!empty($style) && has_post_thumbnail()) {
        preg_match_all('/<img.*?(?: |\\t|\\r|\\n)?src=[\'"]?(.+?)[\'"]?(?:(?: |\\t|\\r|\\n)+.*?)?>/sim', $html, $images);
        if (!empty($images) && isset($images[1])) {
            $images[1] = array_unique($images[1]);
            foreach ($images[1] as $item) {
                if (strpos($item, $upload_url_path) !== false && strpos($item, $style) === false) {
                    $html = str_replace($item, $item . $style, $html);
                }
            }

            $html = str_replace($style . $style, $style, $html);
        }
    }

    return $html;
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
    add_options_page('又拍云 USS', '又拍云 USS', 'manage_options', __FILE__, 'uss_setting_page');
}

add_action('admin_menu', 'uss_add_setting_page');

// 插件设置页面
function uss_setting_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient privileges!');
    }
    $options = [];
    if (!empty($_POST) and $_POST['type'] == 'uss_set') {
        $options['bucket'] = isset($_POST['bucket']) ? sanitize_text_field($_POST['bucket']) : '';
        $options['OperatorName'] = isset($_POST['OperatorName']) ? sanitize_text_field($_POST['OperatorName']) : '';
        $options['OperatorPwd'] = isset($_POST['OperatorPwd']) ? sanitize_text_field($_POST['OperatorPwd']) : '';
        $options['nothumb'] = isset($_POST['nothumb']) ? 'true' : 'false';
        $options['nolocalsaving'] = isset($_POST['nolocalsaving']) ? 'true' : 'false';
        $options['image_process'] = isset($_POST['image_process']) ? sanitize_text_field($_POST['image_process']) : '';
        //仅用于插件卸载时比较使用
        $options['upload_url_path'] = isset($_POST['upload_url_path']) ? sanitize_text_field(stripslashes($_POST['upload_url_path'])) : '';
        $options['update_file_name'] = isset($_POST['update_file_name']) ? sanitize_text_field($_POST['update_file_name']) : 'false';
    }

    if (!empty($_POST) and $_POST['type'] == 'upyun_uss_all') {
        $files = uss_read_dir_queue(get_home_path(), get_option('upload_path'));
        foreach ($files as $file) {
            uss_file_upload($file['key'], $file['filepath']);
        }
        echo '<div class="updated"><p><strong>本次操作成功同步' . count($files) . '个文件</strong></p></div>';
    }

    // 替换数据库链接
    if (!empty($_POST) and $_POST['type'] == 'upyun_uss_replace') {
        $old_url = esc_url_raw($_POST['old_url']);
        $new_url = esc_url_raw($_POST['new_url']);

        if (empty($oldurl) || empty($newurl)) {
            echo '<div class="error"><p><strong>替换域名不能为空。</strong></p></div>';
        } else {
            global $wpdb;

            // 文章内容
            $posts_name = $wpdb->prefix .'posts';
            $posts_result = $wpdb->query("UPDATE $posts_name SET post_content = REPLACE( post_content, '$old_url', '$new_url') ");

            // 修改题图之类的
            $postmeta_name = $wpdb->prefix .'postmeta';
            $postmeta_result = $wpdb->query("UPDATE $postmeta_name SET meta_value = REPLACE( meta_value, '$old_url', '$new_url') ");

            echo '<div class="updated"><p><strong>替换成功！共替换文章内链'.$posts_result.'条、题图链接'.$postmeta_result.'条！</strong></p></div>';
        }
    }

    // 若$options不为空数组，则更新数据
    if ($options !== []) {
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
    $uss_nothumb = $uss_nothumb == 'true';

    $uss_nolocalsaving = esc_attr($uss_options['nolocalsaving']);
    $uss_nolocalsaving = $uss_nolocalsaving == 'true';

    $uss_update_file_name = esc_attr($uss_options['update_file_name']);

    $uss_image_process = esc_attr($uss_options['image_process']);

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
    ?>
    <div class="wrap" style="margin: 10px;">
        <h1>又拍云 USS <span style="font-size: 13px;">当前版本：<?php echo USS_VERSION; ?></span></h1>
        <p>活动推荐：<a href="https://go.qq52o.me/a/upyun" target="_blank">点我注册并完成实名认证，赠送 61 元免费代金券</a></p>
        <p>如果觉得此插件对你有所帮助，不妨到 <a href="https://github.com/sy-records/upyun-uss-wordpress" target="_blank">GitHub</a> 上点个<code>Star</code>，<code>Watch</code>关注更新；<a href="https://go.qq52o.me/qm/ccs" target="_blank">欢迎加入云存储插件交流群，QQ群号：887595381</a>；</p>
        <hr/>
        <form method="post">
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
                        <input type="password" name="OperatorPwd" value="<?php echo $uss_OperatorPwd; ?>" size="50" placeholder="OperatorPwd"/>
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
                        <legend>自动重命名文件</legend>
                    </th>
                    <td>
                        <select name="update_file_name">
                            <option <?php if ($uss_update_file_name == 'false') {echo 'selected="selected"';} ?> value="false">不处理</option>
                            <option <?php if ($uss_update_file_name == 'md5') {echo 'selected="selected"';} ?> value="md5">MD5</option>
                            <option <?php if ($uss_update_file_name == 'time') {echo 'selected="selected"';} ?> value="time">时间戳+随机数</option>
                        </select>
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
                        <legend>图片处理</legend>
                    </th>
                    <td>
                        <input type="text" name="image_process" value="<?php echo $uss_image_process; ?>" size="50" placeholder="请输入版本名称或处理参数，留空表示不处理"/>

                        <p><b>版本名称：</b></p>
                        <p>1）在 <a href="https://console.upyun.com/services/file/" target="_blank">云存储服务管理列表</a> 中对应服务的 <code>图片处理</code> 处添加。具体处理参数设置参考<a href="https://console.upyun.com/services/process/" target="_blank">又拍云文档</a>。</p>
                        <p>2）填写时需要将<code>间隔标识符</code>和对应的<code>版本名称</code>或 <code>处理参数</code>进行拼接，例如：</p>
                        <p><code>间隔标识符</code>为<code>!</code>(感叹号)，<code>版本名称</code>为<code>blog</code>，<code>处理参数</code>为<code>/watermark/text/VVBZVU4g5Y+I5ouN5LqR/align/center/color/52acd9/flip/left-right</code></p>
                        <p>则填写为 <code>!blog</code> 或 <code>!/watermark/text/VVBZVU4g5Y+I5ouN5LqR/align/center/color/52acd9/flip/left-right</code></p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend>保存/更新选项</legend>
                    </th>
                    <td><input type="submit" class="button button-primary" value="保存更改"/></td>
                </tr>
            </table>
            <input type="hidden" name="type" value="uss_set">
        </form>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>
                        <legend>同步历史附件</legend>
                    </th>
                    <input type="hidden" name="type" value="upyun_uss_all">
                    <td>
                        <input type="submit" class="button button-secondary" value="开始同步"/>
                        <p><b>注意：如果是首次同步，执行时间将会十分十分长（根据你的历史附件数量），有可能会因执行时间过长，页面显示超时或者报错。<br> 所以，建议那些几千上万附件的大神们，考虑官方的 <a target="_blank" rel="nofollow" href="https://help.upyun.com/knowledge-base/developer_tools/">同步工具</a></b></p>
                    </td>
                </tr>
            </table>
        </form>
        <hr>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th>
                        <legend>数据库原链接替换</legend>
                    </th>
                    <td>
                        <input type="text" name="old_url" size="50" placeholder="请输入要替换的旧域名"/>
                        <p>如：<code>https://qq52o.me</code></p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend></legend>
                    </th>
                    <td>
                        <input type="text" name="new_url" size="50" placeholder="请输入要替换的新域名"/>
                        <p>如：USS加速域名<code>http://uss-sy-records.test.upcdn.net</code>或自定义域名<code>https://resources.qq52o.me</code></p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <legend></legend>
                    </th>
                    <input type="hidden" name="type" value="upyun_uss_replace">
                    <td>
                        <input type="submit" class="button button-secondary" value="开始替换"/>
                        <p><b>注意：如果是首次替换，请注意备份！此功能会替换文章以及设置的特色图片（题图）等使用的资源链接</b></p>
                    </td>
                </tr>
            </table>
        </form>
    </div>
<?php
}
?>