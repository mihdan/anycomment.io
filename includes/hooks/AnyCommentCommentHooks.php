<?php

/**
 * Class AnyCommentCommentHooks is used to control hooks related to comments.
 */
class AnyCommentCommentHooks {

	/**
	 * AnyCommentCommentHooks constructor.
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Init method of all related hooks.
	 */
	private function init() {
		// Should delete files, likes, etc before comment is deleted
		// as WP by default remove comment meta, which is required to determine attachments IDs
		add_action( 'delete_comment', [ $this, 'process_deleted_comment' ], 10, 2 );

		// Should drop comment cache after it was deleted, just in case
//		add_action( 'deleted_comment', [ $this, 'process_soft_comment' ], 10, 2 );

		// After comment was updated
//		add_action( 'edit_comment', [ $this, 'process_edit_comment' ], 10, 2 );

		// After comment was trashed, marked as spam, etc
//		add_action( 'trashed_comment', [ $this, 'process_soft_comment' ], 10, 2 );
//		add_action( 'untrashed_comment', [ $this, 'process_soft_comment' ], 10, 2 );
//		add_action( 'spam_comment', [ $this, 'process_soft_comment' ], 10, 2 );
//		add_action( 'unspam_comment', [ $this, 'process_soft_comment' ], 10, 2 );

		// On comment status change
//		add_action( 'wp_set_comment_status', [ $this, 'process_set_status_comment' ], 10, 2 );


		// Extend allowed HTML tags to the needs of visual editor
		add_filter( 'wp_kses_allowed_html', [ $this, 'kses_allowed_html_for_quill' ] );
	}

	/**
	 * Extend list of allowed HTML tags.
	 *
	 * @param $allowedtags
	 *
	 * @return mixed
	 */
	public function kses_allowed_html_for_quill( $allowedtags ) {

		$allowedtags['p']          = [];
		$allowedtags['a']          = [ 'href', 'target', 'rel' ];
		$allowedtags['ul']         = [];
		$allowedtags['ol']         = [];
		$allowedtags['blockquote'] = [ 'class' ];
		$allowedtags['code']       = [];
		$allowedtags['li']         = [];
		$allowedtags['b']          = [];
		$allowedtags['i']          = [];
		$allowedtags['u']          = [];
		$allowedtags['strong']     = [];
		$allowedtags['em']         = [];
		$allowedtags['br']         = [];
		$allowedtags['img']        = [ 'class', 'src', 'alt' ];
		$allowedtags['figure']     = [];
		$allowedtags['iframe']     = [];

		return $allowedtags;
	}

	/**
	 * Process comment which will be deleted soon in order to clean-up after it.
	 *
	 * @param int $comment_id Comment ID.
	 * @param WP_Comment $comment Comment object.
	 */
	public function process_deleted_comment( $comment_id, $comment ) {
		// Delete likes of a comment
		AnyCommentLikes::deleteLikes( $comment_id );

		// Delete attached files
		$comment_metas = AnyCommentCommentMeta::getAttachments( $comment_id );

		if ( ! empty( $comment_metas ) ) {
			foreach ( $comment_metas as $comment_meta ) {
				AnyCommentUploadedFiles::delete( $comment_meta->file_id );
			}
		}
	}

	/**
	 * Drops cache of updated comment.
	 *
	 * @param int $comment_id Comment ID.
	 * @param mixed $data Comment data.
	 */
	public function process_edit_comment( $comment_id, $data ) {
	}

	/**
	 * Flush cache of comment after changing its status.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $status Status to be assigned.
	 */
	public function process_set_status_comment( $comment_id, $status ) {
	}

	/**
	 * Soft touch on the comment, such as flushing, etc.
	 *
	 * Primarily used for (un)span, (un)trash comment, etc.
	 *
	 * @param int $comment_id Comment ID.
	 * @param WP_Comment $comment Comment object.
	 */
	public function process_soft_comment( $comment_id, $comment ) {
	}
}