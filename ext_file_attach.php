<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'ext_file_attach';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '1.0.5';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Add file upload ability to com_connect';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@public
ext_file_invalid_type => Field <strong>{field}</strong> is not of the expected type.
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
if (txpinterface === 'public') {
    register_callback('ext_file_attach', 'comconnect.deliver');

    // Register tags if necessary.
    if (class_exists('\Textpattern\Tag\Registry')) {
        Txp::get('\Textpattern\Tag\Registry')
            ->register('com_connect_file');
    }
}

/**
 * Callback hook for com_connect to handle attaching the file.
 *
 * @param  string $evt     Textpattern event
 * @param  string $stp     Textpattern step (action)
 * @param  array  $payload Delivery content, passed in from com_connect
 */
function ext_file_attach($evt, $stp, &$payload)
{
    global $com_connect_error;

    $file_attached = false;

    foreach ($payload['fields'] as $key => $value) {
        if (strpos($key, 'ext_file_') === 0) {
            $file_size = $value['size'];
            $file_type = $value['type'];
            $file_name = $value['name'];
            $file_temp = $value['tmp_name'];
            $file_error = $value['error'];
            $out = '';

            // Check for errors.
            // This is rarely triggered unfortunately because most browsers validate file sizes and types,
            // throwing empty arrays or just silently failing on our behalf. Grrr.
            // Not only that, com_connect doesn't know what to do with any error strings at the moment so it'd just
            // report a generic 'sorry' message.
            if ($file_error > 0) {
                switch ($file_error) {
                    case 1:
                    case 2:
                        $max = ext_file_max();
                        $userMax = !empty($payload['fields']['MAX_FILE_SIZE']) ? $payload['fields']['MAX_FILE_SIZE'] : $max;
                        $max = min($userMax, $max);
                        $com_connect_error[] = gTxt('com_connect_maxval_warning', array('{field}' => gTxt('upload_file'), '{value}' => $max));
                        $out = 'comconnect.fail';
                        break;
                    case 3:
                        // File only partially uploaded.
                        $out = 'comconnect.fail';
                        break;
                    case 4:
                        // No file uploaded: no worries, ignore it. Field is probably not required.
                        break;
                    case 6:
                        // Missing temporary folder.
                        $out = 'comconnect.fail';
                        break;
                }

                return $out;
            } else {
                $handle = fopen($file_temp, 'r');
                $content = fread($handle, $file_size);
                fclose($handle);

                // Only one file can be attached per message.
                $encoded_content = chunk_split(base64_encode($content));
                $file_attached = true;
            }

            // TODO: delete temp file or does PHP do it?
            break;
        }
    }

    if ($file_attached) {
        $fileBoundary = md5('boundary1');
        $textBoundary = md5('boundary2');
        $sep = PHP_EOL;

        $payload['headers']['MIME-Version'] = '1.0';
        $payload['headers']['content_type'] = 'multipart/mixed; boundary=' . $fileBoundary;
        $payload['body'] = '--' . $fileBoundary . $sep
            . 'Content-Type: multipart/alternative; boundary=' . $textBoundary . $sep
            . '--' . $textBoundary . $sep
            . 'Content-Type: text/plain; charset=utf-8' . $sep . $sep
            . $payload['body'] . $sep
            . '--' . $textBoundary . '--' . $sep
            . '--' . $fileBoundary . $sep
            . 'Content-Type:' . $file_type . '; '
                . 'name="' . $file_name . '"' . $sep
            . 'Content-Transfer-Encoding:base64' . $sep
            . 'Content-Disposition:attachment; '
                . 'filename="' . $file_name . '"' . $sep
            . 'X-Attachment-Id:' . rand(1000, 9000) . $sep . $sep
            . $encoded_content . $sep
            . '--' . $fileBoundary . '--';
    }

    // Back to com_connect to mail out the modified content
    return;
}

/**
 * Tag: Render a file input field.
 *
 * @param  array  $atts Tag attributes
 * @return string HTML
 */
function com_connect_file($atts)
{
    global $com_connect_error, $com_connect_submit, $com_connect_flags;

    $max_upload_size = ext_file_max();

    extract(com_connect_lAtts(array(
        'accept'         => '',
        'break'          => br,
        'class'          => 'comFile',
        'html_form'      => $com_connect_flags['this_form'],
        'isError'        => '',
        'label'          => gTxt('com_connect_file'),
        'label_position' => 'before',
        'max'            => $max_upload_size,
        'placeholder'    => '',
        'required'       => $com_connect_flags['required'],
        'type'           => 'file',
    ), $atts));

    $doctype = get_pref('doctype', 'xhtml');

    // Convert to bytes if not already
    $max = ext_file_parse_size($max);

    if (empty($name)) {
        $name = com_connect_label2name($label);
    }

    if (empty($_FILES) && empty($_POST) && isset($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
        // Pretend it submitted okay, to render errors.
        $com_connect_submit = 1;
    }

    if ($com_connect_submit) {
        $hlabel = txpspecialchars($label);

        if (array_key_exists($name, $_FILES)) {
            $fileInfo = $_FILES[$name];
            $acceptableTypes = do_list($accept);

            if (!empty($fileInfo['size']) && ($fileInfo['size'] > $max)) {
                $com_connect_error[] = gTxt('com_connect_maxval_warning', array('{field}' => $hlabel, '{value}' => $max));
                $isError = "errorElement";
            } elseif ($accept && $fileInfo['name'] !== '') {
                $isOK = false;

                foreach ($acceptableTypes as $acceptable) {
                    if (strpos($acceptable, '.') === 0) {
                        // It's a file extension check.
                        if (strpos($fileInfo['name'], $acceptable) !== false) {
                            $isOK = true;
                            break;
                        }
                    } else {
                        // It's a MIME type check.
                        if (in_array($fileInfo['type'], $acceptableTypes)) {
                            $isOK = true;
                            break;
                        }
                    }
                }

                if ($isOK) {
                    com_connect_store('ext_file_' . $name, $label, $fileInfo);
                } else {
                    $com_connect_error[] = gTxt('ext_file_invalid_type', array('{field}' => $hlabel));
                    $isError = "errorElement";
                }
            } else {
                com_connect_store('ext_file_' . $name, $label, $fileInfo);
            }
        } elseif ($required && empty($_FILES)) {
            $com_connect_error[] = gTxt('com_connect_maxval_warning', array('{field}' => $hlabel, '{value}' => $max));
            $isError = "errorElement";
        } elseif ($required) {
            $com_connect_error[] = gTxt('com_connect_field_missing', array('{field}' => $hlabel));
            $isError = "errorElement";
        }
    }

    // PHP max file size helper. Not infallible but useful.
    $maxhidden = ($max) ? parse('<com::connect_text type="hidden" name="MAX_FILE_SIZE" class="comHidden" min="" max="" default="'.$max.'" required="0" label="" />') : '';
    unset($atts['max']);

    // Core attributes.
    $attr = com_connect_build_atts(array(
        'accept' => $accept,
        'id'     => (isset($id) ? $id : $name),
        'name'   => $name,
        'type'   => $type,
    ));

    // HTML5 attributes.
    $required = ($required) ? 'required' : '';

    if ($doctype !== 'xhtml') {
        $attr += com_connect_build_atts(array(
            'form'         => $html_form,
            'placeholder'  => $placeholder,
            'required'     => $required,
        ));
    }

    // Global attributes.
    $attr += com_connect_build_atts($com_connect_globals, $atts);

    $classes = array();

    foreach (array($class, ($required ? 'comRequired' : ''), $isError) as $cls) {
        if ($cls) {
            $classes[] = $cls;
        }
    }

    $classStr = ($classes ? ' class="' . implode(' ', $classes) . '"' : '');
    $labelStr = '<label for="' . $name . '"' . $classStr . '>' . txpspecialchars($label) . '</label>';

    return ($label_position === 'before' ? $labelStr . $break : '') .
        $maxhidden.
        '<input' . $classStr . ($attr ? ' ' . implode(' ', $attr) : '') . ' />' .
        ($label_position === 'after' ? $break . $labelStr : '') .
        script_js(<<<EOJS
function com_ext_attach_handler() {
    var b = document.getElementById('{$html_form}');
    b.setAttribute("enctype", "multipart/form-data");
}

if (document.readyState != 'loading') {
    com_ext_attach_handler();
} else if (document.addEventListener) {
    document.addEventListener('DOMContentLoaded', com_ext_attach_handler);
} else document.attachEvent('onreadystatechange', function() {
    if (document.readyState=='interactive') {
        com_ext_attach_handler()();
    }
});
EOJS
);
}

// Returns a file size limit in bytes.
function ext_file_max()
{
    $max_size = -1;

    if ($max_size < 0) {
        // Start with post_max_size.
        $max_size = ext_file_parse_size(ini_get('post_max_size'));

        // If upload_max_size is less, then reduce. Except if
        // zero, which indicates no limit.
        $upload_max = ext_file_parse_size(ini_get('upload_max_filesize'));

        if ($upload_max > 0 && $upload_max < $max_size) {
            $max_size = $upload_max;
        }

        // If Textpattern's file_max_upload_size is less, then reduce. Except if
        // zero, which indicates no limit.
        $upload_max = get_pref('file_max_upload_size');

        if ($upload_max > 0 && $upload_max < $max_size) {
            $max_size = $upload_max;
        }
    }

    return $max_size;
}

/**
 * Convert a size value with suffix (K, M, G, T, etc) to bytes.
 */
function ext_file_parse_size($size)
{
    $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
    $size = preg_replace('/[^0-9\.\,]/', '', $size); // Remove the non-numeric characters from the size.

   if ($unit) {
        // Find the position of the unit in the ordered string which is
        // the power of magnitude to multiply a kilobyte by.
        return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
    } else {
        return round($size);
    }
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. ext_file_attach

Textpattern CMS plugin module for the "com_connect plugin":https://plugins.textpattern.com/plugins/com_connect to allow simple file uploading as an attachment to the contact email.

h2. Pre-requisites

* Textpattern CMS v4.6.x or higher.
* com_connect plugin v4.6.0 or higher installed and enabled.

h2. Usage

Somewhere in your @<txp:com_connect>@ form/container, add the @<txp:com_connect_file />@ tag. It accepts all the usual HTML5 attributes for regular input elements (see com_connect's documentation). Attributes that are specific to this tag:

* @accept="comma-separated values"@ List of acceptable file extensions (including the leading dot), or valid MIME types. Note that this is not particularly robust and can be fooled by merely changing the file extension of the file being uploaded. Omitted = all files.
* @max="value"@ The maximum file size permitted. You can specify this value in bytes, or with units, e.g. 600k is 600 Kilobytes, 2m is 2 Megabytes, and so on. If omitted, uses whichever is smaller of the 'Maximum file size of uploads' preference or @php.ini@'s @upload_max_filesize@ / @post_max_size@ configuration. Note that if you specify a value greater than the ones in the PHP or Textpattern configs, your value will be ignored and the smallest system-imposed limit will be used instead. You can use any one of these suffixes when specifying the max value:
** b (bytes)
** k (kilobytes)
** m (megabytes)
** g (gigabytes)
** t (terabytes)
** p (petabytes)
** e (exabytes)
** z (zetabytes)
** y (yotabytes)

Note that only one file is currently permitted for upload. Suggest customers Zip files up if sending multiples.

Upon submission, the plugin tries to catch as many error conditions as possible, but different browsers react in different ways to size/MIME type violations, so there may be instances in which the form just 'fails' silently without reporting why. Also, some (most?) recipient email systems annoyingly apply spam filtering and heuristics that will silently drop any messages they feel are dangerous or spammy. So a successful send is no guarantee of successful reception of the message and its attached payload.

h2. Notes

* The plugin tries to detect file size violations _before_ going through the rigmarole of uploading it by using a cheat built into PHP. The success of this is system/browser dependent.
* If you try to submit a file that exceeds the limit and it is not caught by the browser before the upload begins, you will be notified of this fact by the server afterwards. But at that point, as far as com_connect is concerned, the form is tainted and has been "used". Thus if you try to simply select another file and resubmit, you will be told the form has already been submitted. There is sadly no workaround for this at present.

# --- END PLUGIN HELP ---
-->
<?php
}
?>
