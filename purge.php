<?php
if (extension_loaded('apc') && ini_get('apc.enabled')) {
    apc_clear_cache();
    apc_clear_cache('user');
    apc_clear_cache('opcode');
}

require_once 'app/Mage.php';
Mage::app('admin');

// Purge
if (!empty($_POST)) {
    $url = $_POST['url'];
    $helper = Mage::helper('aoestatic')->purge(array($url));

    // Warm up cache
    $ch = curl_init();
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $url));
    curl_exec($ch);
    curl_close($ch);

    echo 'Cache purged!<br/><br/><hr/><br/>';
}
?>

<form action="purge.php" method="POST">
    <input size="100" type="text" name="url" id="url" placeholder="URL">
    <input type="submit" value="Purge">
</form>
