#!fruithost:permission:root
<?php
	use fruithost\Storage\Database;
	use fruithost\Localization\I18N;
	
	require_once(sprintf('%s/providers/Provider.interface.php', dirname(__FILE__)));
	
	class SSLDaemon {
		private $providers			= [];
		private $certificates		= [];
		
		public function __construct() {
			$this->init();
			$this->loadCertificates();
			$this->handleCertificates();			
			$this->reloadApache();
		}
		
		protected function init() {
			if(!file_exists('/etc/fruithost/config/apache2/ssl/')) {
				mkdir('/etc/fruithost/config/apache2/ssl/');
			}
			
			@chmod('/etc/fruithost/config/apache2/ssl', 0777);
			
			foreach([
				'LetsEncrypt',
				'SelfSigned',
				'Certificate'
			] AS $provider) {
				$old = get_declared_classes();
				require_once(sprintf('%s/providers/%s.class.php', dirname(__FILE__), $provider));
				$new = get_declared_classes();

				foreach(array_diff($new, $old) AS $class) {
					if(is_subclass_of($class, 'fruithost\\Module\\SSL\\Providers\\Provider', true)) {
						$reflect					= new \ReflectionClass($class);
						$instance					= $reflect->newInstance();
						$this->providers[$provider]	= $instance;
					}
				}
			}
		}
		
		protected function loadCertificates() {
			foreach(Database::fetch('SELECT `' . DATABASE_PREFIX . 'certificates`.*, `' . DATABASE_PREFIX . 'domains`.`name` AS `name`, `' . DATABASE_PREFIX . 'domains`.`directory` AS `directory` FROM `' . DATABASE_PREFIX . 'certificates` INNER JOIN `' . DATABASE_PREFIX . 'domains` ON `' . DATABASE_PREFIX . 'certificates`.`domain`=`' . DATABASE_PREFIX . 'domains`.`id` AND `' . DATABASE_PREFIX . 'certificates`.`user_id`=`' . DATABASE_PREFIX . 'domains`.`user_id`') AS $cert) {
				if($cert->time_created == null || $cert->time_updated != null) {
					$this->certificates[] = $cert;
				} else {
					// Check if Cert is expired
					if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $cert->name)) && is_readable(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $cert->name))) {
					
					// Cert-File not exists, recreate it..
					} else {
						$this->certificates[] = $cert;
					}
				}
			}
		}
		
		protected function deleteCertificate($certificate) {
			print "\033[31;31m\tDELETING:\033[39m " . $certificate->name . PHP_EOL;
			
			if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $certificate->name))) {
				unlink(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $certificate->name));
			}
			
			if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.key', $certificate->name))) {
				unlink(sprintf('/etc/fruithost/config/apache2/ssl/%s.key', $certificate->name));
			}
			
			if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.root', $certificate->name))) {
				unlink(sprintf('/etc/fruithost/config/apache2/ssl/%s.root', $certificate->name));
			}
			
			// Remove SSL-VHost
			if(file_exists(sprintf('/etc/fruithost/config/apache2/vhosts/20_%s.ssl.conf', $certificate->name))) {
				unlink(sprintf('/etc/fruithost/config/apache2/vhosts/20_%s.ssl.conf', $certificate->name));
			}
			
			Database::delete(DATABASE_PREFIX . 'certificates', [
				'id'			=> $certificate->id
			]);
			
			// Force Domain for renewal VHost
			Database::update(DATABASE_PREFIX . 'domains', 'id', [
				'id'			=> $certificate->domain,
				'time_created'	=> NULL,
				'time_deleted'	=> NULL
			]);
		}
		
		protected function createVirtualHost($domain, $force_ssl = true, $hsts = null, $grant_all = true) {
			$path		= sprintf('%s%s%s', HOST_PATH, $domain->username, $domain->directory);
			$config		= '# Generated by fruithost' . PHP_EOL;
			$path_cert	= sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $domain->name);
			$path_key	= sprintf('/etc/fruithost/config/apache2/ssl/%s.key', $domain->name);
			
			# Force HTTPS-Redirect
			if($force_ssl) {
				$config .= PHP_EOL;
				$config .= '<VirtualHost *:80>' . PHP_EOL;
				$config .= TAB . sprintf('ServerAdmin abuse@%s', $domain->name) . PHP_EOL;
				$config .= TAB . sprintf('ServerName %s', $domain->name) . PHP_EOL;
				$config .= TAB . 'RewriteEngine On' . PHP_EOL;
				$config .= TAB . 'RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [L,R=301,E=nocache:1]' . PHP_EOL;
				$config .= '</VirtualHost>' . PHP_EOL;
			}
			
			# SSL VHost
			$config .= PHP_EOL;
			$config .= '<VirtualHost *:443>' . PHP_EOL;
			$config .= TAB . sprintf('ServerAdmin abuse@%s', $domain->name) . PHP_EOL;
			$config .= TAB . sprintf('DocumentRoot %s', $path) . PHP_EOL;
			$config .= TAB . sprintf('ServerName %s', $domain->name) . PHP_EOL;
			
			$logs = sprintf('%s%s/%s/', HOST_PATH, $domain->username, 'logs');
			$config .= TAB . sprintf('ErrorLog %s%s_error.log', $logs, $domain->name) . PHP_EOL;
			$config .= TAB . sprintf('CustomLog %s%s_access.log combined', $logs, $domain->name) . PHP_EOL;
			
			if(file_exists($path_cert)) {
				$config .= PHP_EOL;
				$config .= TAB . 'SSLEngine on' . PHP_EOL;
				$config .= TAB . sprintf('SSLCertificateFile %s', $path_cert) . PHP_EOL;
				$config .= TAB . sprintf('SSLCertificateKeyFile %s', $path_key) . PHP_EOL;
			}
			
			if($hsts !== null) {
				$config .= PHP_EOL;
				$config .= TAB . sprintf('Header always set Strict-Transport-Security "max-age=%d; includeSubDomains"', (60 * 60 * 24 * $hsts)) . PHP_EOL;
			}
			
			$config .= PHP_EOL;
			
			foreach([
				100, 101,
				400, 401, 403, 404, 405, 408, 410, 411, 412, 413, 414, 415,
				500, 501, 502, 503, 504, 505, 506
			] AS $code) {
				$config .= TAB . sprintf('Alias /errors/%1$s.html /etc/fruithost/placeholder/errors/%1$s.html', $code) . PHP_EOL;
			}
			
			$config .= PHP_EOL;
			$config .= TAB . sprintf('<Directory %s>', $path) . PHP_EOL;
			$config .= TAB . TAB . 'Options +FollowSymLinks -Indexes' . PHP_EOL;
			$config .= TAB . TAB . 'AllowOverride All' . PHP_EOL;
			
			if($grant_all) {
				$config .= TAB . TAB . 'Require all granted' . PHP_EOL;
			}
			
			$config .= TAB . '</Directory>' . PHP_EOL;
			
			$config .= PHP_EOL;
			$config .= TAB .  '<Files ~ "(^\.|php\.ini$)">' . PHP_EOL;
			$config .= TAB . TAB . 'Require all denied' . PHP_EOL;
			$config .= TAB . '</Files>' . PHP_EOL;
			
			$config .= '</VirtualHost>' . PHP_EOL;
			
			file_put_contents(sprintf('/etc/fruithost/config/apache2/vhosts/20_%s.ssl.conf', $domain->name), $config);
		}
		
		protected function handleCertificates() {
			foreach($this->certificates AS $certificate) {
				$provider = null;
		
				switch($certificate->type) {
					case 'LETSENCRYPT':
						$provider = $this->providers['LetsEncrypt'];
					break;
					case 'SELFSIGNED':
						$provider = $this->providers['SelfSigned'];
					break;
					case 'CERTIFICATE':
						$provider = $this->providers['Certificate'];
					break;
				}
				
				if($certificate->type == 'DELETED') {
					$this->deleteCertificate($certificate);
					continue;
				}
				
				if(empty($provider)) {
					print "\033[31;31m\tHandling Domain:\033[39m " . $certificate->name . " \033[36m(no provider given!)\033[39m" . PHP_EOL;
					continue;
				}
				
				print "\033[31;32m\tHandling Domain:\033[39m " . $certificate->name . " \033[36m(" . $provider->getName() . ")\033[39m" . PHP_EOL;
				
				$domain = Database::single('SELECT
										`' . DATABASE_PREFIX . 'domains`.*,
										`' . DATABASE_PREFIX . 'users`.`username` AS `username`
									FROM
										`' . DATABASE_PREFIX . 'domains`,
										`' . DATABASE_PREFIX . 'users`
									WHERE
										`' . DATABASE_PREFIX . 'users`.`id`=`' . DATABASE_PREFIX . 'domains`.`user_id`
									AND
										`' . DATABASE_PREFIX . 'domains`.`type`=\'DOMAIN\'
									AND
										`' . DATABASE_PREFIX . 'domains`.`id`=:id
									ORDER BY `' . DATABASE_PREFIX . 'domains`.`name` ASC', [ 'id' => $certificate->domain ]);
					
				$directory	= sprintf('%s%s%s', HOST_PATH, $domain->username, $certificate->directory);
				
				if($certificate->time_created == null) {
					$exec = trim($provider->execute($certificate->name, $directory));
					
					if(!empty($exec)) {
						print shell_exec($exec);
					}
				}
				
				if($exec === false) {
					// Error?
					Database::update(DATABASE_PREFIX . 'certificates', 'id', [
						'id'			=> $certificate->id,
						'days_expiring'	=> $provider->renewPeriod()
					]);
					continue;
				}
				
				if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.key', $certificate->name))) {
					chmod(sprintf('/etc/fruithost/config/apache2/ssl/%s.key', $certificate->name), 0644);
				}
				
				if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $certificate->name))) {
					chmod(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $certificate->name), 0644);
				}
				
				if(file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.root', $certificate->name))) {
					chmod(sprintf('/etc/fruithost/config/apache2/ssl/%s.root', $certificate->name), 0644);
				}
				
				// Create only VHost, when Cert is given (to prevent inaccessible)
				if(!file_exists(sprintf('/etc/fruithost/config/apache2/ssl/%s.cert', $certificate->name))) {
					print "\033[31;31m\tWarning:\033[39m " . $certificate->name . " currently not ready to inject SSL \033[36m(Cert-File not exists)\033[39m" . PHP_EOL;
					continue;
				}
				
				// Create SSL-VHost
				$this->createVirtualHost($domain, ($certificate->force_https === 'YES'), (($certificate->enable_hsts === 'YES') ? $provider->renewPeriod() : null));
				
				// When Domain currently not created/finished
				if(!file_exists(sprintf('/etc/fruithost/config/apache2/vhosts/10_%s.conf', $certificate->name))) {
					print "\033[31;31m\tWarning:\033[39m " . $certificate->name . " currently not ready to inject SSL \033[36m(Domain already in creation-phase!)\033[39m" . PHP_EOL;
					continue;
				}
				
				// Empty Original VHost (when automatic HTTPS-Redirect
				if($certificate->force_https == 'YES' && file_exists(sprintf('/etc/fruithost/config/apache2/vhosts/10_%s.conf', $certificate->name))) {
					file_put_contents(sprintf('/etc/fruithost/config/apache2/vhosts/10_%s.conf', $certificate->name), '# Generated by fruithost' . PHP_EOL . '# Modified by SSL-Module (see *.ssl.conf file)!');
				}
				
				if($certificate->time_created == null) {
					Database::update(DATABASE_PREFIX . 'certificates', 'id', [
						'id'			=> $certificate->id,
						'days_expiring'	=> $provider->renewPeriod(),
						'time_created'	=> date('Y-m-d H:i:s', time())
					]);
				} else {
					Database::update(DATABASE_PREFIX . 'certificates', 'id', [
						'id'			=> $certificate->id,
						'days_expiring'	=> $provider->renewPeriod(),
						'time_updated'	=> null
					]);
				}
			}
		}
		
		protected function reloadApache() {
			print shell_exec('service apache2 reload');
		}
	}
	
	new SSLDaemon();
?>