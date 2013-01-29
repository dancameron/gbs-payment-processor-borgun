<?php
/*
Plugin Name: Group Buying Payment Processor - Borgun Payments
Version: .1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Borgun Payments
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_borgun');
function gb_load_borgun() {
	require_once('groupBuyingBorgun.class.php');
}