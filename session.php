<?php
session_start();
$_SESSION['src_host'] = $_POST['src_host'];
$_SESSION['src_db']   = $_POST['src_db'];
$_SESSION['src_user'] = $_POST['src_user'];
$_SESSION['src_pass'] = $_POST['src_pass'];

$_SESSION['dst_host'] = $_POST['dst_host'];
$_SESSION['dst_db']   = $_POST['dst_db'];
$_SESSION['dst_user'] = $_POST['dst_user'];
$_SESSION['dst_pass'] = $_POST['dst_pass'];

unset($_SESSION['tables']); // reset