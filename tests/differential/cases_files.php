<?php
/**
 * Differential cases: FilesTable save with Upload (6 in Cake 3, 8 in Cake 5).
 * The upload representation differs by version (Cake 3 $_FILES array vs Cake 5 PSR-7
 * UploadedFileInterface), so each case branches on DIFF_CAKE5 and compares the OBSERVABLE
 * outcome: saved row fields, destination file existence and content. Saved rows and moved
 * files are cleaned up inside the case; run.sh resets the database between images anyway.
 * PHP 7.4 compatible.
 */
require __DIR__ . '/lib.php';

function files_upload($filename, $content, $error, $private)
{
    $tmp = tempnam(sys_get_temp_dir(), 'diffup');
    file_put_contents($tmp, $content);
    if (DIFF_CAKE5) {
        return new \Laminas\Diactoros\UploadedFile($tmp, strlen($content), $error, $filename, 'text/plain');
    }
    return ['name' => $filename, 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($content)];
}

function files_save_case($filename, $content, $error, $private)
{
    return function () use ($filename, $content, $error, $private) {
        $t = diff_table('Files');
        $e = $t->newEntity(['event_id' => '1001', 'file' => files_upload($filename, $content, $error, $private), 'private' => $private]);
        $errors = diff_errors($e);
        $saved = $t->save($e);
        $dest = WWW_ROOT . 'files' . DS . '1001' . DS . $filename;
        $out = ['saved' => (bool)$saved, 'errors' => $errors];
        if ($saved) {
            $fresh = $t->get($e->id);
            $out['row'] = ['file' => $fresh->file, 'dir' => $fresh->dir, 'type' => $fresh->type, 'event_id' => $fresh->event_id, 'private' => $fresh->private];
            $out['dest_exists'] = file_exists($dest);
            $out['dest_content'] = file_exists($dest) ? file_get_contents($dest) : null;
            $t->delete($fresh);
        } else {
            $out['dest_exists'] = file_exists($dest);
        }
        if (file_exists($dest)) {
            unlink($dest);
        }
        $dir = WWW_ROOT . 'files' . DS . '1001';
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }
        $base = WWW_ROOT . 'files';
        if (is_dir($base) && count(scandir($base)) === 2) {
            rmdir($base);
        }
        return $out;
    };
}

$cases = [];
$cases['files:save:upload'] = files_save_case('hello.txt', 'hello upload', UPLOAD_ERR_OK, false);
$cases['files:save:upload-private'] = files_save_case('secret.txt', 'hello upload', UPLOAD_ERR_OK, true);
$cases['files:save:no-file'] = function () {
    $t = diff_table('Files');
    $e = $t->newEntity(['event_id' => '1001', 'private' => false]);
    return ['errors' => diff_errors($e)];
};
$cases['files:save:err-no-file'] = files_save_case('empty.txt', '', UPLOAD_ERR_NO_FILE, false);

diff_main($cases);

