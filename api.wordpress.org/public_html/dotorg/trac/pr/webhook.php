<?php
namespace WordPressdotorg\API\Trac\GithubPRs;

require dirname( dirname( dirname( __DIR__ ) ) ) . '/init.php';
require dirname( dirname( dirname( __DIR__ ) ) ) . '/includes/hyperdb/bb-10-hyper-db.php';

require __DIR__ . '/functions.php';
require __DIR__ . '/class-trac.php';

function verify_signature() {
	// Validate that the request came from GitHub.
	if ( ! defined( 'GH_PRBOT_WEBHOOK_SECRET' ) ) {
		return;
	}

	$sent_signature     = $_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '';
	$expected_signature = 'sha1=' . hash_hmac( 'sha1', file_get_contents( 'php://input' ), GH_PRBOT_WEBHOOK_SECRET );

	if ( ! hash_equals( $expected_signature, $sent_signature ) ) {
		header( 'HTTP/1.0 403 Forbidden' );
		die('-1');
	}
}

verify_signature();

$payload = json_decode( file_get_contents( 'php://input' ) );

switch ( $_SERVER['HTTP_X_GITHUB_EVENT'] ) {
	// Pull Request
	case 'pull_request':

		// A Pull Request has been created, updated, sync'd or reviewed.
		// Ensure our DB is up-to-date with this news.

		$pr_repo   = $payload->pull_request->base->repo->full_name;
		$pr_number = $payload->number;

		// API call to get the latest PR details, not all actions that trigger this include the full PR details.
		$pr_data   = fetch_pr_data( $pr_repo, $pr_number );

		// Step 1. Is this PR associated with any Trac tickets?
		$existing_refs = $wpdb->get_results( $wpdb->prepare(
			"SELECT trac, ticket FROM trac_github_prs" .
			" WHERE repo = %s and pr = %d",
			$pr_repo, $pr_number
		) );
	
		// Step 2. Is that Trac Ticket still what we expect?
		$matched_existing_ref = false;
		foreach ( $existing_refs as $ref ) {
			if (
				$ref->trac === $pr_data->trac_ticket[0] &&
				$ref->ticket === $pr_data->trac_ticket[1]
			) {
				$matched_existing_ref = true;
			}
		}

		$_pr_data_no_ticket = clone $pr_data;
		unset( $_pr_data_no_ticket->trac_ticket );

		// Step 3. If not in DB, or $pr_data->trac_ticket isn't yet in the DB, add a new row of it.
		if ( $pr_data->trac_ticket && ( ! $existing_refs || ! $matched_existing_ref ) ) {
			$wpdb->insert(
				'trac_github_prs',
				[
					'created'      => gmdate( 'Y-m-d H:i:s', strtotime( $pr_data->created_at ) ),
					'last_checked' => gmdate( 'Y-m-d H:i:s' ),
					'trac'         => $pr_data->trac_ticket[0],
					'ticket'       => $pr_data->trac_ticket[1],
					'repo'         => $pr_repo,
					'pr'           => $pr_number,
					'data'         => json_encode( $_pr_data_no_ticket ),
				]
			);

			// Add a mention to the Trac Ticket.
			$trac = get_trac_instance( $pr_data->trac_ticket[0] );

			$trac->update(
				$pr_data->trac_ticket[1],
				"''This ticket was mentioned in [{$pr_data->html_url} PR #{$pr_number}] " .
					"on [https://github.com/{$pr_repo}/ {$pr_repo}] " .
					"by [{$pr_data->user->url} {$pr_data->user->name}].''"
			);
		}

		// Step 4. Update all the instances of this PR with the new data, it may be linked to multiple tickets/tracs.
		$wpdb->update(
			'trac_github_prs',
			[
				'last_checked' => gmdate( 'Y-m-d H:i:s' ),
				'data'         => json_encode( $_pr_data_no_ticket ),
			],
			[
				'repo' => $pr_repo,
				'pr'   => $pr_number,
			]
		);

		die( 'OK' );
		break;

	case 'issue_comment':
		// This is a singular comment on a PR or issue.
		// NOT a code review comment.

		$is_edit = isset( $payload->action ) && 'edited' === $payload->action;
		if ( $is_edit ) {
			// UNIQUE constraint failed: ticket_change.ticket, ticket_change.time, ticket_change.field
			die( 'UNSUPPORTED - EDIT' );
		}

		// Make sure it's a PR comment..
		if ( empty( $payload->issue->pull_request ) ) {
			die( 'UNSUPPORTED - Only PR comments supported.' );
		}

		$pr_repo   = $payload->repository->full_name;
		$pr_number = $payload->issue->number;

		// Find the tickets that this PR is attached to.
		$tickets = $wpdb->get_results( $wpdb->prepare(
			"SELECT trac, ticket FROM trac_github_prs WHERE repo = %s AND pr = %d",
			$pr_repo,
			$pr_number
		) );
		if ( ! $tickets ) {
			die( 'PR Not linked to any tickets' );
		}

		$comment_template = "{{{#!comment\n%s\n}}}\n[%s %s] commented on [%s %s]:\n\n%s";
		$comment_author   = find_wporg_user_by_github( $payload->comment->user->login );
		$comment_time     = new \DateTime( $payload->comment->created_at );

		$comment_body = sprintf(
			$comment_template,
			$payload->comment->id,
			$payload->comment->user->html_url,
			$payload->comment->user->login,
			$payload->comment->html_url,
			'PR #' . $payload->issue->number,
			$payload->comment->body
		);

		foreach ( $tickets as $t ) {
			$trac = get_trac_instance( $t->trac );

			if ( ! $is_edit ) {
				$trac->update(
					$t->ticket, $comment_body,
					[], false,
					$comment_author,
					$comment_time
				);
			} else {
				// TODO: Need to edit..
				// use /wpapi endpoint for that.
			}

		}

		die( 'OK' );

	case 'pull_request_review':
		// This is a Pull Request Review.
		// It's comprised of one or more comments, which are related to a users "review"
		// The comment(s) in this review may actually be a response to another review or response to a review in response to a review, etc.

		die( 'N/A' );
	case 'pull_request_review_comment':
		// This is a comment within a Pull Request Review, but sent before the review is published.

		die( 'Not Needed?' );
		
}