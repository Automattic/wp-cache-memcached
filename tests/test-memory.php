<?php

use Automattic\Memcached\Memcached_Adapter;

/**
 * @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes its own properties.
 */
class Test_Cache_Memory extends PHPUnit\Framework\TestCase {

	/** @param array<string|int, string> $values */
	private function create_cache( array $values = [] ): WP_Object_Cache {
		$adapter = $this->createStub( Memcached_Adapter::class );
		$adapter->method( 'get_connections' )->willReturn( [] );
		$adapter->method( 'get_default_connections' )->willReturn( [] );
		$adapter->method( 'get_connection_errors' )->willReturn( [] );
		$cache                                      = new WP_Object_Cache( $adapter );
		$cache->flush_number[ $cache->blog_prefix ] = 1;

		$mapped_values = [];
		foreach ( $values as $key => $value ) {
			$mapped_values[ $cache->key( $key, 'default' ) ] = $value;
		}
		$adapter->method( 'get_multiple' )->willReturn( $mapped_values );

		return $cache;
	}

	public function test_bulk_read_does_not_allocate_a_serialized_copy_of_the_batch(): void {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'Measuring an isolated peak requires PHP 8.2.' );
		}

		$values = [];
		for ( $i = 0; $i < 64; ++$i ) {
			$values[ $i ] = str_repeat( 'x', 512 * 1024 );
		}
		$cache = $this->create_cache( $values );
		$keys  = array_keys( $values );

		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$results = $cache->get_multiple( $keys );
		$extra   = memory_get_peak_usage() - $before;

		self::assertSame( $values, $results );
		self::assertLessThan( 8 * 1024 * 1024, $extra, 'Bulk-read bookkeeping should not duplicate the 32 MiB payload.' );
		self::assertSame( 32 * 1024 * 1024, $cache->size_total );
	}
}
