<?php
/**
 * Stands in for Action Scheduler's as_enqueue_async_action().
 *
 * @package SoapboxBlyg
 */

// phpcs:ignoreFile -- a test stand-in for another library's function, under that library's name.
function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
	\SoapboxBlyg\Tests\PurgeTest::$calls['queued'][] = func_get_args();
	// 0 is what Action Scheduler answers when it could not take the action.
	return \SoapboxBlyg\Tests\PurgeTest::$queue_refuses ? 0 : 1;
}
