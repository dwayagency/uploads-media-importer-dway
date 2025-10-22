<?php
/**
 * Plugin Name: Uploads Media Importer (DT)
 * Description: Scansiona wp-content/uploads, trova i file presenti ma non indicizzati e li importa come media (attachment) in batch, con interfaccia in Admin.
 * Version: 1.0.0
 * Author: DWAY SRL
 * Author URI: https://dway.agency
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

class DT_Uploads_Media_Importer {
	const SLUG = 'dt-uploads-media-importer';
	const NONCE = 'dt_umi_nonce';
	const OPTION_LAST_SCAN = 'dt_umi_last_scan';

	public function __construct() {
		add_action('admin_menu', [$this, 'add_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue']);
		add_action('wp_ajax_dt_umi_scan', [$this, 'ajax_scan']);
		add_action('wp_ajax_dt_umi_import', [$this, 'ajax_import']);
	}

	public function add_menu() {
		add_media_page(
			__('Uploads Importer', 'dt-umi'),
			__('Uploads Importer', 'dt-umi'),
			'upload_files',
			self::SLUG,
			[$this, 'render_page']
		);
	}

	public function enqueue($hook) {
		if ($hook !== 'media_page_' . self::SLUG) { return; }
		wp_enqueue_script(
			'dt-umi',
			plugins_url('js/dt-umi.js', __FILE__),
			['jquery'],
			'1.0.0',
			true
		);
		wp_localize_script('dt-umi', 'DTUMI', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce(self::NONCE),
		]);
		wp_enqueue_style('dt-umi-css', plugins_url('css/dt-umi.css', __FILE__), [], '1.0.0');
	}

	private function capcheck() {
		if (!current_user_can('upload_files')) {
			wp_send_json_error(['message' => __('Permessi insufficienti.', 'dt-umi')], 403);
		}
	}

	public function render_page() {
		if (!current_user_can('upload_files')) { wp_die(__('Permessi insufficienti.', 'dt-umi')); }
		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit($uploads['basedir']);
		$baseurl = trailingslashit($uploads['baseurl']);
		$last = get_option(self::OPTION_LAST_SCAN);
		?>
		<div class="wrap dt-umi-wrap">
			<h1>Uploads Media Importer</h1>
			<p class="description">Scansiona la cartella <code><?php echo esc_html($basedir); ?></code> e importa i file non indicizzati nella Libreria Media.</p>

			<div class="card">
				<h2 class="title">Impostazioni scansione</h2>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="dt-umi-subpath">Sottocartella</label></th>
						<td>
							<input type="text" id="dt-umi-subpath" class="regular-text" placeholder="es. 2025/10" />
							<p class="description">Lascia vuoto per scansionare tutto <code>uploads</code>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dt-umi-exts">Estensioni</label></th>
						<td>
							<input type="text" id="dt-umi-exts" class="regular-text" value="jpg,jpeg,png,gif,webp,svg,pdf" />
							<p class="description">Lista separata da virgole. Verranno considerati solo questi tipi.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dt-umi-batch">Dimensione batch</label></th>
						<td>
							<input type="number" id="dt-umi-batch" value="25" min="1" max="200" />
							<p class="description">Numero di file processati per richiesta (utile per evitare timeout).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Opzioni</th>
						<td>
							<label><input type="checkbox" id="dt-umi-recursive" checked /> Ricorsiva nelle sottocartelle</label><br/>
							<label><input type="checkbox" id="dt-umi-dryrun" /> Dry run (non creare allegati, solo anteprima)</label><br/>
							<label><input type="checkbox" id="dt-umi-only-missing-thumbs" /> Genera solo metadati minimi per non immagini</label>
						</td>
					</tr>
				</table>

				<p>
					<button class="button button-secondary" id="dt-umi-scan">Scansiona</button>
					<button class="button button-primary" id="dt-umi-import" disabled>Importa mancanti</button>
				</p>
				<div id="dt-umi-status"></div>
				<div id="dt-umi-results" class="dt-umi-results hidden"></div>
			</div>

			<p class="description">Base URL uploads: <code><?php echo esc_html($baseurl); ?></code>.
			<?php if ($last) { echo 'Ultima scansione: <code>' . esc_html($last) . '</code>'; } ?></p>
		</div>
		<?php
	}

	private function list_files($dir, $recursive, $allowed_exts) {
		$files = [];
		if (!is_dir($dir)) return $files;
		$iterator = $recursive
			? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))
			: new DirectoryIterator($dir);

		foreach ($iterator as $file) {
			if ($file instanceof SplFileInfo && $file->isFile()) {
				$ext = strtolower($file->getExtension());
				if (!empty($allowed_exts) && !in_array($ext, $allowed_exts, true)) continue;
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	private function find_missing($fullpaths, $uploads_basedir) {
		$missing = [];
		$existing_rel = $this->existing_relative_paths_map();
		$basedir = trailingslashit($uploads_basedir);
		$basedir_len = strlen($basedir);
		foreach ($fullpaths as $path) {
			if (strpos($path, $basedir) !== 0) continue; // safety
			$rel = str_replace('\\', '/', substr($path, $basedir_len));
			if (!$rel) continue;
			if (!isset($existing_rel[$rel])) $missing[$rel] = $path;
		}
		return $missing;
	}

	private function existing_relative_paths_map() {
		global $wpdb;
		$map = [];
		$results = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");
		if ($results) {
			foreach ($results as $rel) { $map[$rel] = true; }
		}
		return $map;
	}

	public function ajax_scan() {
		$this->capcheck();
		check_ajax_referer(self::NONCE, 'nonce');

		$uploads = wp_get_upload_dir();
		$base = trailingslashit($uploads['basedir']);
		$sub = sanitize_text_field($_POST['sub'] ?? '');
		$recursive = !empty($_POST['recursive']);
		$exts = sanitize_text_field($_POST['exts'] ?? '');
		$allowed = array_filter(array_map('strtolower', array_map('trim', explode(',', $exts))));
		$dir = $base . ltrim($sub, '/');

		$all = $this->list_files($dir, $recursive, $allowed);
		$missing = $this->find_missing($all, $uploads['basedir']);
		update_option(self::OPTION_LAST_SCAN, current_time('mysql'));

		wp_send_json_success([
			'total_found'   => count($all),
			'total_missing' => count($missing),
			'sample'        => array_slice(array_keys($missing), 0, 20),
			'context_dir'   => $dir,
		]);
	}

	public function ajax_import() {
		$this->capcheck();
		check_ajax_referer(self::NONCE, 'nonce');

		$uploads = wp_get_upload_dir();
		$basedir = trailingslashit($uploads['basedir']);
		$baseurl = trailingslashit($uploads['baseurl']);
		$sub = sanitize_text_field($_POST['sub'] ?? '');
		$recursive = !empty($_POST['recursive']);
		$exts = sanitize_text_field($_POST['exts'] ?? '');
		$allowed = array_filter(array_map('strtolower', array_map('trim', explode(',', $exts))));
		$dir = $basedir . ltrim($sub, '/');
		$batch = max(1, min(200, intval($_POST['batch'] ?? 25)));
		$dry   = !empty($_POST['dry']);
		$only_min_meta = !empty($_POST['only_min_meta']);

		$all = $this->list_files($dir, $recursive, $allowed);
		$missing = $this->find_missing($all, $uploads['basedir']);
		$queue = array_values($missing);

		$processed = [];
		$errors = [];
		$count = 0;
		while ($count < $batch && !empty($queue)) {
			$path = array_shift($queue);
			$rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $path), '/');

			if (!file_exists($path)) { $errors[] = [ 'file' => $rel, 'error' => 'File non trovato']; continue; }
			$ft = wp_check_filetype(basename($path));
			$mime = $ft['type'] ?: 'application/octet-stream';

			if ($dry) {
				$processed[] = [ 'file' => $rel, 'id' => 0, 'dry' => true ];
				$count++; continue;
			}

			$post_date = null; $post_date_gmt = null;
			if (preg_match('#^(\\d{4})/(\\d{2})/#', $rel, $m)) {
				$y = (int) $m[1]; $mo = (int) $m[2];
				if ($y >= 1970 && $y <= 2100 && $mo >= 1 && $mo <= 12) {
					$tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
					$d = new DateTime(sprintf('%04d-%02d-01 12:00:00', $y, $mo), $tz);
					$post_date = $d->format('Y-m-d H:i:s');
					$du = clone $d; $du->setTimezone(new DateTimeZone('UTC')); $post_date_gmt = $du->format('Y-m-d H:i:s');
				}
			}
			$attachment = [
				'post_title'     => sanitize_file_name(pathinfo($path, PATHINFO_FILENAME)),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
			];
			if ($post_date && $post_date_gmt) {
				$attachment['post_date'] = $post_date;
				$attachment['post_date_gmt'] = $post_date_gmt;
			}
			$attach_id = wp_insert_attachment($attachment, $path);
			if (is_wp_error($attach_id)) {
				$errors[] = [ 'file' => $rel, 'error' => $attach_id->get_error_message() ];
				continue;
			}

			require_once ABSPATH . 'wp-admin/includes/image.php';

			$metadata = [];
			if (strpos($mime, 'image/') === 0 && !in_array($ft['ext'], ['svg'], true)) {
				$metadata = wp_generate_attachment_metadata($attach_id, $path);
				if (!empty($metadata)) {
					wp_update_attachment_metadata($attach_id, $metadata);
				}
			} else {
				// Metadati minimi (per non immagini / svg)
				if (!$only_min_meta) {
					// Prova comunque a popolare dimensioni se possibile
					$metadata = [ 'filesize' => filesize($path) ];
					wp_update_attachment_metadata($attach_id, $metadata);
				}
			}

			$processed[] = [ 'file' => $rel, 'id' => $attach_id, 'url' => $baseurl . str_replace('\\', '/', $rel) ];
			$count++;
		}

		$remaining = max(0, count($missing) - count($processed));
		wp_send_json_success([
			'processed' => $processed,
			'errors'    => $errors,
			'remaining' => $remaining,
			'batch'     => $batch,
		]);
	}
}

new DT_Uploads_Media_Importer();

/**
 * Assets inline (JS/CSS)
 */
add_action('admin_footer', function(){
	$screen = get_current_screen();
	if (!$screen || $screen->id !== 'media_page_' . DT_Uploads_Media_Importer::SLUG) return;
	?>
	<script id="dt-umi-inline-js">
	(function($){
		let queueRemaining = 0;
		function msg(html){ $('#dt-umi-status').html(html); }
		function log(line){ $('#dt-umi-results').removeClass('hidden').append($('<div/>').text(line)); }
		function params(){
			return {
				action: '',
				nonce: DTUMI.nonce,
				sub: $('#dt-umi-subpath').val(),
				exts: $('#dt-umi-exts').val(),
				recursive: $('#dt-umi-recursive').is(':checked') ? 1 : 0,
				batch: parseInt($('#dt-umi-batch').val(), 10) || 25,
				dry: $('#dt-umi-dryrun').is(':checked') ? 1 : 0,
				only_min_meta: $('#dt-umi-only-missing-thumbs').is(':checked') ? 1 : 0,
			};
		}
		$('#dt-umi-scan').on('click', function(){
			const p = params(); p.action = 'dt_umi_scan';
			msg('Scansione in corso…'); $('#dt-umi-results').empty();
			$.post(DTUMI.ajaxUrl, p).done(function(r){
				if(!r.success){ msg('Errore: ' + (r.data && r.data.message ? r.data.message : 'sconosciuto')); return; }
				queueRemaining = r.data.total_missing;
				msg('Trovati ' + r.data.total_found + ' file totali. Mancanti in Libreria: ' + r.data.total_missing + '.');
				if (r.data.sample && r.data.sample.length){
					log('Esempi mancanti:'); r.data.sample.forEach(function(rel){ log('• ' + rel); });
				}
				$('#dt-umi-import').prop('disabled', queueRemaining === 0);
			}).fail(function(){ msg('Richiesta fallita.'); });
		});
		function runImport(){
			const p = params(); p.action = 'dt_umi_import';
			msg('Import in corso… (restanti ~' + queueRemaining + ')');
			$.post(DTUMI.ajaxUrl, p).done(function(r){
				if(!r.success){ msg('Errore: ' + (r.data && r.data.message ? r.data.message : 'sconosciuto')); $('#dt-umi-import').prop('disabled', false); return; }
				const processed = r.data.processed || [];
				const errors = r.data.errors || [];
				queueRemaining = r.data.remaining || 0;
				processed.forEach(function(item){
					const line = (item.dry ? '[DRY] ' : '') + (item.file || '') + (item.id ? ' -> ID ' + item.id : '');
					log(line);
				});
				errors.forEach(function(e){
					log('ERR ' + (e.file || '') + ': ' + (e.error || ''));
				});
				if (queueRemaining > 0){
					msg('Import in corso… (restanti ~' + queueRemaining + ')');
					setTimeout(runImport, 250);
				} else {
					msg('Import completato.');
					$('#dt-umi-import').prop('disabled', false);
				}
			}).fail(function(){
				msg('Richiesta fallita.');
				$('#dt-umi-import').prop('disabled', false);
			});
		}
		$('#dt-umi-import').on('click', function(){
			$(this).prop('disabled', true);
			$('#dt-umi-results').empty().removeClass('hidden');
			runImport();
		});
	})(jQuery);
	</script>
	<?php
	});
