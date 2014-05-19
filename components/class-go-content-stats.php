<?php

class GO_Content_Stats
{
	public $wpcom_api_key = FALSE; // get yours at http://apikey.wordpress.com/
	public $config;
	public $date_greater_stamp;
	public $date_greater;
	public $date_lesser_stamp;
	public $date_lesser;
	public $calendar;

	public function __construct( $config = array() )
	{
		$this->config = (array) $config;

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu_init' ) );
	} // END __construct

	// add the menu item to the dashboard
	public function admin_menu_init()
	{
		$this->menu_url = admin_url( 'index.php?page=go-content-stats' );

		add_submenu_page( 'index.php', 'Gigaom Content Stats', 'Content Stats', 'edit_posts', 'go-content-stats', array( $this, 'admin_menu' ) );

		add_action( 'go-content-stats-posts', array( $this, 'prime_pv_cache' ) );
	} // END admin_menu_init

	public function init()
	{
		if ( ! is_admin() )
		{
			return;
		}// end if

		wp_enqueue_style( 'go-content-stats', plugins_url( 'css/go-content-stats.css', __FILE__ ), array(), '1' );
	} // END init

	// the stats page/admin menu
	public function admin_menu()
	{
		// prep the config vars so we don't have to check them later
		if ( ! isset( $this->config['taxonomies'] ) )
		{
			$this->config['taxonomies'] = array();
		}

		if ( ! isset( $this->config['content_matches'] ) )
		{
			$this->config['content_matches'] = array();
		}

		// prefix the matches so we can avoid collissions
		foreach ( $this->config['content_matches'] as $k => $v )
		{
			$this->config['content_matches'][ 'match_' . $k ] = $v;
			unset( $this->config['content_matches'][ $k ] );
		}

		echo '<div class="wrap">';
		screen_icon( 'index' );

		// set the upper limit of posts
		if ( isset( $_GET['date_greater'] ) && strtotime( urldecode( $_GET['date_greater'] ) ) )
		{
			$this->date_greater_stamp = strtotime( urldecode( $_GET['date_greater'] ) );
			$this->date_greater = date( 'Y-m-d', $this->date_greater_stamp );
		}
		else
		{
			$this->date_greater_stamp = time();
			$this->date_greater = date( 'Y-m-d', $this->date_greater_stamp );
		}

		// set the lower limit of posts
		if ( isset( $_GET['date_lesser'] ) && strtotime( urldecode( $_GET['date_lesser'] ) ) )
		{
			$this->date_lesser_stamp = strtotime( urldecode( $_GET['date_lesser'] ) );
			$this->date_lesser = date( 'Y-m-d', $this->date_lesser_stamp );
		}
		else
		{
			$this->date_lesser_stamp = strtotime( '-31 days' );
			$this->date_lesser = date( 'Y-m-d', $this->date_lesser_stamp );
		}

		// prefill the results list
		$this->pieces = (object) array_merge(

			array(
				'day' => NULL,
				'posts' => NULL,
				'pvs' => NULL,
				'comments' => NULL,
			),

			array_fill_keys( array_keys( $this->config['content_matches'] ), NULL )
		);

		$temp_time = $this->date_lesser_stamp;
		do
		{
			$temp_date = date( 'Y-m-d', $temp_time );
			$this->calendar[ $temp_date ] = clone $this->pieces;
			$this->calendar[ $temp_date ]->day = $temp_date;
			$temp_time += 86400;
		}// end do
		while ( $temp_time < $this->date_greater_stamp );
		$this->calendar = array_reverse( $this->calendar );

		// run the stats
		if ( 'author' == $_GET['type'] && ( $author = get_user_by( 'id', $_GET['key'] ) ) )
		{
				echo '<h2>Gigaom Content Stats for ' . esc_html( $author->display_name ) . '</h2>';
				$this->get_author_stats( $_GET['key'] );
		}// end if
		elseif ( taxonomy_exists( $_GET['type'] ) && term_exists( $_GET['key'], $_GET['type'] ) )
		{
				echo '<h2>Gigaom Content Stats for ' . sanitize_title_with_dashes( $_GET['type'] ) . ':' .  sanitize_title_with_dashes( $_GET['key'] ) . '</h2>';
				$this->get_taxonomy_stats( $_GET['type'], $_GET['key'] );
		}// end elseif
		else
		{
			echo '<h2>Gigaom Content Stats</h2>';
			$this->get_general_stats();
		}// end else

		echo '<h2>Select a knife to slice through the stats</h2>';

		// display a picker for the time period
		echo '<h3>Time period</h3>';
		$this->pick_month();

		// print lists of items people can get stats on
		// authors here
		$authors = $this->get_authors_list();
		if ( is_array( $authors ) )
		{
			echo '<h3>Authors</h3>';
			$this->do_list( $authors );
		}

		// all configured taxonomies here
		foreach ( $this->config['taxonomies'] as $tax )
		{
			$terms = $this->get_terms_list( $tax );
			if ( is_array( $terms ) )
			{
				echo '<h3>' . esc_html( $tax ) . '</h3>';
				$this->do_list( $terms, $tax );
			}// end if
		}// end foreach

		// show the api key to help debugging
		if ( empty( $this->wpcom_api_key ) )
		{
			echo '<p>WPCom stats using API Key '. $this->get_wpcom_api_key() .'</p>';
		}

		echo '</div>';
	} // END admin_menu

	// a filter for the posts sql to limit by date range
	public function posts_where( $where = '' )
	{
		$where .= " AND post_date <= '{$this->date_greater}' AND post_date >= '{$this->date_lesser}'";
		return $where;
	} // END posts_where

	// get a list of all posts matching the time selector
	public function get_general_stats()
	{
		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		$query = new WP_Query( array(
			'posts_per_page' => -1,
		) );
		remove_filter( 'posts_where', array( $this, 'posts_where' ) );

		if ( ! isset( $query->posts ) )
		{
			return FALSE;
		}

		return $this->display_stats( $query->posts );
	} // END get_general_stats

	// get a list of posts by author to display
	public function get_author_stats( $author )
	{
		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		$query = new WP_Query( array(
			'author' => (int) $author,
			'posts_per_page' => -1,
		) );
		remove_filter( 'posts_where', array( $this, 'posts_where' ) );

		if ( ! isset( $query->posts ) )
		{
			return FALSE;
		}

		return $this->display_stats( $query->posts );
	} // END get_author_stats

	// get a list of posts by taxonomy to display
	public function get_taxonomy_stats( $taxonomy, $term )
	{
		add_filter( 'posts_where', array( $this, 'posts_where' ) );
		$query = new WP_Query( array(
			'taxonomy' => $taxonomy,
			'term' => $term,
			'posts_per_page' => -1,
		) );
		remove_filter( 'posts_where', array( $this, 'posts_where' ) );

		if ( ! isset( $query->posts ) )
		{
			return FALSE;
		}

		return $this->display_stats( $query->posts );
	} // END get_taxonomy_stats

	// actually display the stats for the selected posts
	public function display_stats( $posts )
	{
		if ( ! is_array( $posts ) )
		{
			return FALSE;
		}

		do_action( 'go-content-stats-posts', wp_list_pluck( $posts, 'ID' ) );

		// iterate through the posts, aggregate their stats, and assign those into the calendar
		foreach ( $posts as $post )
		{
			$post_date = date( 'Y-m-d', strtotime( $post->post_date ) );
			$this->calendar[ $post_date ]->day = $post_date;
			$this->calendar[ $post_date ]->posts++;
			$this->calendar[ $post_date ]->pvs += $this->get_pvs( $post->ID );
			$this->calendar[ $post_date ]->comments += $post->comment_count;
			foreach ( $this->config['content_matches'] as $key => $match )
			{
				if ( preg_match( $match['regex'], $post->post_content ) )
				{
					$this->calendar[ $post_date ]->$key++;
				}
			}// end foreach
		}// end foreach

		// create a sub-list of content match table headers
		$content_match_th = '';
		if ( is_array( $this->config['content_matches'] ) )
		{
			foreach ( $this->config['content_matches'] as $match )
			{
				$content_match_th .= '<th>' . $match['label'] . '</th>';
			}
		}

		// display the aggregated stats in a table
		echo '
		<h3>Post performance by date published</h3>
		<table border="0" cellspacing="0" cellpadding="0">
			<tr>
				<th>Day</th>
				<th>Posts</th>
				<th>PVs</th>
				<th>PVs/post</th>
				<th>Comments</th>
				<th>Comments/post</th>
				' . $content_match_th .'
			</tr>
		';

		// iterate through and generate the summary stats (yes, this means I'm iterating extra)
		$summary = clone $this->pieces;
		foreach ( $this->calendar as $day )
		{
			$summary->day++;
			$summary->posts += $day->posts;
			$summary->pvs += $day->pvs;
			$summary->comments += $day->comments;
			foreach ( $this->config['content_matches'] as $key => $match )
			{
				$summary->$key += $day->$key;
			}
		}// end foreach

		// iterate the content matches for the summary
		$content_match_summary_values = '';
		foreach ( $this->config['content_matches'] as $key => $match )
		{
			$content_match_summary_values .= '<td>' . ( $summary->$key ? $summary->$key : 0 ) . '</td>';
		}// end foreach

		// print the summary row for all these stats
		printf( '
			<tr class="summary">
				<td>%1$s</td>
				<td>%2$s</td>
				<td>%3$s</td>
				<td>%4$s</td>
				<td>%5$s</td>
				<td>%6$s</td>
				%7$s
			</tr>',

			$summary->day .' days',
			$summary->posts ? $summary->posts : 0,
			$summary->pvs ? number_format( $summary->pvs ) : 0,
			$summary->posts ? number_format( ( $summary->pvs / $summary->posts ), 1 ) : 0,
			$summary->comments ? number_format( $summary->comments ) : 0,
			$summary->posts ? number_format( ( $summary->comments / $summary->posts ), 1 ) : 0,
			$content_match_summary_values
		);

		// iterate through the calendar (includes empty days), print stats for each day
		foreach ( $this->calendar as $day )
		{
			// iterate the content matches for each row
			$content_match_row_values = '';
			foreach ( $this->config['content_matches'] as $key => $match )
			{
				$content_match_row_values .= '<td>' . ( $day->$key ? $day->$key : '&nbsp;' ) . '</td>';
			}

			printf( '
				<tr>
					<td>%1$s</td>
					<td>%2$s</td>
					<td>%3$s</td>
					<td>%4$s</td>
					<td>%5$s</td>
					<td>%6$s</td>
					%7$s
				</tr>',

				$day->day,
				$day->posts ? '<a href="' . admin_url( '/edit.php?m=' . $day->day ) . '">' . $day->posts . '</a>' : '&nbsp;',
				$day->pvs ? number_format( $day->pvs ): '&nbsp;',
				$day->posts ? number_format( ( $day->pvs / $day->posts ), 1 ) : '&nbsp;',
				$day->comments ? number_format( $day->comments ) : '&nbsp;',
				$day->posts ? number_format( ( $day->comments / $day->posts ), 1 ) : '&nbsp;',
				$content_match_row_values
			);
		}// end foreach

		// print the summary row for all these stats
		printf( '
			<tr class="summary-footer">
				<td>%1$s</td>
				<td>%2$s</td>
				<td>%3$s</td>
				<td>%4$s</td>
				<td>%5$s</td>
				<td>%6$s</td>
				%7$s
			</tr>',

			$summary->day .' days',
			$summary->posts ? $summary->posts : 0,
			$summary->pvs ? number_format( $summary->pvs ) : 0,
			$summary->posts ? number_format( ( $summary->pvs / $summary->posts ), 1 ) : 0,
			$summary->comments ? number_format( $summary->comments ) : 0,
			$summary->posts ? number_format( ( $summary->comments / $summary->posts ), 1 ) : 0,
			$content_match_summary_values
		);

		echo '</table>';
	} // END display_stats

	public function get_wpcom_api_key()
	{
		$api_key = FALSE;

		// a locally set API key overrides everything
		if ( ! empty( $this->wpcom_api_key ) )
		{
			$api_key = $this->wpcom_api_key;
		}
		// attempt to get the API key from the user
		elseif (
			( $user = wp_get_current_user() ) &&
			isset( $user->api_key )
		)
		{
			$api_key = $user->api_key;
		}

		return $api_key;
	}//end get_wpcom_api_key

	// get pageviews for the given post ID from Automattic's stats API
	public function get_pvs( $post_id )
	{
		// test the cache like a good API user
		// if the prime_pv_cache() cache method earlier is working, this should always return a cached result
		if ( ! $hits = wp_cache_get( $post_id, 'go-content-stats-hits' ) )
		{
			// attempt to get the API key
			if ( ! $api_key = $this->get_wpcom_api_key() )
			{
				return NULL;
			}


			// the api has some very hacker-ish docs at http://stats.wordpress.com/csv.php
			$get_url = sprintf(
				 'http://stats.wordpress.com/csv.php?api_key=%1$s&blog_uri=%2$s&table=postviews&post_id=%3$d&days=-1&limit=-1&format=json&summarize',
				 $api_key,
				 urlencode( home_url() ),
				 $post_id
			);

			$hits_api = wp_remote_request( $get_url );
			if ( ! is_wp_error( $hits_api ) )
			{
				$hits_api = wp_remote_retrieve_body( $hits_api );
				$hits_api = json_decode( $hits_api );

				if ( isset( $hits_api->views ) )
				{
					$hits = $hits_api->views;
				}
				else
				{
					$hits = NULL;
				}

				wp_cache_set( $post_id, $hits, 'go-content-stats-hits', 1800 );
			}// end if
		}// end if

		return $hits;
	} // END get_pvs

	// prime the pageview stats cache by doing a bulk query of all posts, rather than individual queries
	public function prime_pv_cache( $post_ids )
	{
		// caching this, but the result doesn't really matter so much as the fact that
		// we've already run it on a specific set of posts recently
		$cachekey = md5( serialize( $post_ids ) );

		// test the cache like a good API user
		if ( ! $hits = wp_cache_get( $cachekey, 'go-content-stats-hits-bulk' ) )
		{
			// attempt to get the API key
			if ( ! $api_key = $this->get_wpcom_api_key() )
			{
				return NULL;
			}

			// the api has some very hacker-ish docs at http://stats.wordpress.com/csv.php
			$get_url = sprintf(
				 'http://stats.wordpress.com/csv.php?api_key=%1$s&blog_uri=%2$s&table=postviews&post_id=%3$s&days=-1&limit=-1&format=json&summarize',
				 $api_key,
				 urlencode( home_url() ),
				 implode( ',', array_map( 'absint', $post_ids ) )
			);

			$hits_api = wp_remote_request( $get_url );
			if ( ! is_wp_error( $hits_api ) )
			{
				$hits_api = wp_remote_retrieve_body( $hits_api );
				$hits_api = json_decode( $hits_api );

				if ( ! isset( $hits_api[0]->postviews ) )
				{
					return;
				}

				foreach ( $hits_api[0]->postviews as $hits_api_post )
				{
					if ( ! isset( $hits_api_post->post_id, $hits_api_post->views ) )
					{
						continue;
					}

					// the real gold here is setting the cache entry for the get_pv method to use later
					wp_cache_set( $hits_api_post->post_id, $hits_api_post->views, 'go-content-stats-hits', 1800 );
				}

				wp_cache_set( $cachekey, $hits_api[0]->postviews, 'go-content-stats-hits-bulk', 1800 );
			}// end if
		}// end if
	} // END prime_pv_cache

	// print a list of items to get stats on
	public function do_list( $list, $type = 'author' )
	{
		if ( ! is_array( $list ) )
		{
			return FALSE;
		}

		echo '<ul>';
		foreach ( $list as $item )
		{
			printf( '<li><a href="%1$s&type=%2$s&key=%3$s">%4$s (%5$d)</a></li>',
				$this->menu_url,
				$type,
				$item->key,
				$item->name,
				$item->hits
			);
		}
		echo '</ul>';
	} // END do_list

	// get a list of authors from actual posts (rather than just authors on the blog)
	// cached for a full day
	public function get_authors_list()
	{
		if ( ! $return = wp_cache_get( 'authors', 'go-content-stats' ) )
		{
			global $wpdb;

			$author_ids = $wpdb->get_results( "SELECT post_author, COUNT(1) AS hits FROM {$wpdb->posts} GROUP BY post_author" );

			if ( ! is_array( $author_ids ) )
			{
				return FALSE;
			}

			$return = array();
			foreach ( $author_ids as $author_id )
			{
				$name = get_the_author_meta( 'display_name', $author_id->post_author );
				$return[ $author_id->post_author ] = (object) array(

					'key' => $author_id->post_author,
					'name' => $name ? $name : 'No author name',
					'hits' => $author_id->hits,
				);
			}

			wp_cache_set( 'authors', $return, 'go-content-stats', 86413 ); // 86413 is a prime number slightly longer than 24 hours
		}// end if

		return $return;
	} // END get_authors_list

	// get a list of the most popular terms in the given taxonomy
	public function get_terms_list( $taxonomy )
	{
		if ( ! taxonomy_exists( $taxonomy ) )
		{
			return FALSE;
		}

		$terms = get_terms( $taxonomy, array(
			'orderby' => 'count',
			'order' => 'DESC',
			'number' => 23,
		) );

		if ( ! is_array( $terms ) )
		{
			return FALSE;
		}

		$return = array();
		foreach ( $terms as $term )
		{
			$return[ $term->term_id ] = (object) array(

				'key' => $term->slug,
				'name' => $term->name,
				'hits' => $term->count,
			);
		}// end foreach

		return $return;
	} // END get_terms_list

	public function pick_month()
	{
		$months = array();
		$months[] = '<option value="' . date( 'Y-m', strtotime( '-31 days' ) ) . '">Last 30 days</option>';
		$starting_month = (int) date( 'n' );
		for ( $year = (int) date( 'Y' ); $year >= 2001; $year-- )
		{
			for ( $month = $starting_month; $month >= 1; $month-- )
			{
				$temp_time = strtotime( $year . '-' . $month . '-1' );
				$months[] = '<option value="' . date( 'Y-m', $temp_time ) . '" ' . selected( date( 'Y-m', $this->date_lesser_stamp ), date( 'Y-m', $temp_time ), FALSE ) . '>' . date( 'M Y', $temp_time ) . '</option>';
			}// end for

			$starting_month = 12;
		}// end for

		?>
		<select onchange="window.location = window.location.href.split('?')[0] + '?page=go-content-stats&date_lesser=' + this.value + '-1' + '&date_greater=' + this.value + '-31'">
			<?php echo implode( $months ); ?>
		</select>
		<?php
	} // END pick_month
}// END GO_Content_Stats

function go_content_stats( $config )
{
	global $go_content_stats;

	if ( ! is_object( $go_content_stats ) )
	{
		$go_content_stats = new GO_Content_Stats( $config );
	}

	return $go_content_stats;
} // END go_content_stats
