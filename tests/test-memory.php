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
		$adapter->method( 'add' )->willReturn( true );
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

	public function test_small_bulk_read_does_not_copy_the_existing_runtime_cache(): void {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'Measuring an isolated peak requires PHP 8.2.' );
		}

		$cache = $this->create_cache( [
			'fresh' => 'new',
			'stale' => 'remote',
		] );
		for ( $i = 0; $i < 50000; ++$i ) {
			$cache->cache[ $cache->key( $i, 'default' ) ] = [
				'value' => 'existing',
				'found' => true,
			];
		}
		$cache->cache[ $cache->key( 'stale', 'default' ) ] = [
			'value' => 'old',
			'found' => true,
		];

		memory_reset_peak_usage();
		$before  = memory_get_usage();
		$results = $cache->get_multiple( [ 'stale', 'fresh', 'absent' ], 'default', true );
		$extra   = memory_get_peak_usage() - $before;

		self::assertLessThan( 1024 * 1024, $extra, 'Adding three entries should not copy the existing 50,000-entry cache table.' );
		self::assertSame( [
			'stale'  => 'remote',
			'fresh'  => 'new',
			'absent' => false,
		], $results );
		self::assertSame( 'existing', $cache->get( 0 ) );
		self::assertSame( 'remote', $cache->get( 'stale' ) );
		self::assertSame( 'new', $cache->get( 'fresh' ) );
		self::assertFalse( $cache->get( 'absent', 'default', false, $found ) );
		self::assertFalse( $found );
	}

	/**
	 * @psalm-suppress MixedArgument The public statistics API exposes untyped arrays.
	 * @psalm-suppress MixedArrayAccess
	 */
	public function test_operation_history_is_bounded_across_groups_without_losing_totals(): void {
		$cache = $this->create_cache();
		for ( $i = 0; $i < 3000; ++$i ) {
			$cache->group_ops_stats( 'get', '1:wp_:key', 'group-' . $i, 8, 0.01 );
		}

		self::assertCount( 1000, $cache->group_ops['slow-ops'] );
		self::assertCount( 1001, $cache->group_ops );
		$stats = $cache->get_stats();
		self::assertCount( 1000, $stats['operations']['get'] );
		self::assertSame( 3000, $stats['operation_counts']['get'] );
		self::assertSame( 3000, $stats['operation_counts']['slow-ops'] );
		self::assertSame( 24000, $stats['totals']['size'] );
		self::assertEqualsWithDelta( 30.0, $stats['totals']['query_time'], 0.000001 );

		$cache->flush_runtime();
		self::assertCount( 0, $cache->group_ops );
		$cache->group_ops_stats( 'get', '1:wp_:key', 'after-reset', 8, 0.01 );
		self::assertCount( 1, $cache->group_ops['after-reset'] );
		self::assertCount( 1, $cache->group_ops['slow-ops'] );
		self::assertSame( 3001, $cache->stats['get'] );
	}

	/** @psalm-suppress MixedArgument The public statistics API exposes untyped arrays. */
	public function test_local_hits_do_not_consume_the_operation_history_budget(): void {
		$cache = $this->create_cache();
		for ( $i = 0; $i < 3000; ++$i ) {
			$cache->group_ops_stats( 'get_local', '1:wp_:key', 'default' );
		}
		$cache->group_ops_stats( 'get', '1:wp_:key', 'default', 8, 0.001 );

		self::assertCount( 1, $cache->group_ops['default'] );
		self::assertSame( 3000, $cache->stats['get_local'] );
	}

	/** @dataProvider suspended_add_modes */
	public function test_suspended_additions_do_not_retain_values( bool $bulk, string $group ): void {
		$cache = $this->create_cache();
		$cache->add_non_persistent_groups( [ 'runtime' ] );
		$previous = wp_suspend_cache_addition();
		try {
			wp_suspend_cache_addition( true );
			if ( $bulk ) {
				$values = [
					'one' => 'value',
					'two' => 'value',
				];
				self::assertSame( [
					'one' => false,
					'two' => false,
				], $cache->add_multiple( $values, $group ) );
			} else {
				self::assertFalse( $cache->add( 'one', 'value', $group ) );
			}
			self::assertSame( [], $cache->cache );
			self::assertSame( 0, $cache->stats['add'] );

			wp_suspend_cache_addition( false );
			self::assertTrue( $cache->add( 'one', 'value', $group ) );
			self::assertSame( 'value', $cache->get( 'one', $group ) );
		} finally {
			wp_suspend_cache_addition( $previous );
		}
	}

	public function suspended_add_modes(): array {
		return [
			'single persistent' => [ false, 'default' ],
			'single runtime'    => [ false, 'runtime' ],
			'bulk persistent'   => [ true, 'default' ],
			'bulk runtime'      => [ true, 'runtime' ],
		];
	}
}
