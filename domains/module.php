<?php
	use fruithost\Modules\ModuleInterface;
	use fruithost\Storage\Database;
	use fruithost\Accounting\Auth;
	use fruithost\UI\Button;
	use fruithost\UI\Modal;
	use fruithost\Localization\I18N;
	
	class Domains extends ModuleInterface {
		private $domains = [];
		
		public function init() : void {
			$this->addModal((new Modal('create_domain', I18N::get('Create Domain'), sprintf('%s/views/create.php', dirname(__FILE__))))->addButton([
				(new Button())->setName('cancel')->setLabel(I18N::get('Cancel'))->addClass('btn-outline-danger')->setDismissable(),
				(new Button())->setName('create')->setLabel(I18N::get('Create'))->addClass('btn-outline-success')
			])->onSave([ $this, 'onSave' ]));
			
			$this->domains = Database::fetch('SELECT * FROM `' . DATABASE_PREFIX . 'domains` WHERE `user_id`=:user AND `type`=\'DOMAIN\' ORDER BY `name` ASC', [
				'user'	=> Auth::getID()
			]);
			
			/* Registrierng Filters */
			$this->addFilter('MODULE_DOMAIN_LIST_TABLE_NAME', function($data = [], $domain = null) {
				if(empty($data)) {
					$html[] = '';
					return $html;
				}
				
				return $html;
			});
			
			$this->addFilter('MODULE_DOMAIN_LIST_NAME', function($data = [], $domain = null) {
				if(empty($data)) {
					$data = [
						'name'	=> $domain->name,
						'link'	=> '<a href="http://%1$s/" target="_blank">%1$s</a></td>'
					];
				}
				
				return $data;
			});
			
			$this->addFilter('MODULE_DOMAIN_LIST_STATUS', function($data = [], $domain = null) {
				if(empty($data)) {
					$data = [
						'text'	=> I18N::get('Pending'),
						'html'	=> '<span class="badge text-bg-warning" data-bs-toggle="hover" data-bs-title="%s">&nbsp;</span>'
					];
				}
				
				return $data;
			});
		}
		
		public function load() : void {
			if(empty($this->domains)) {
				$this->addButton((new Button())->setName('create')->setLabel(I18N::get('Create'))->addClass('btn-outline-success')->setModal('create_domain'));
			} else {
				$this->addButton([
					(new Button())->setName('create')->setLabel(I18N::get('Create New'))->addClass('btn-outline-success')->setModal('create_domain'),
					(new Button())->setName('delete')->setLabel(I18N::get('Delete selected'))->addClass('btn-outline-danger')
				]);				
			}
		}
		
		public function onPOST($data = []) : void {
			if(isset($data['action'])) {
				switch($data['action']) {
					case 'delete':
						if(!isset($data['domain'])) {
							$this->assign('error', I18N::get('Please select the Domains you want to delete!'));
						} else {
							$deletion	= [];
							$stop		= false;
							foreach($data['domain'] AS $domain_id) {
								$domain = Database::single('SELECT * FROM `' . DATABASE_PREFIX . 'domains` WHERE `id`=:domain_id AND `user_id`=:user_id LIMIT 1', [
									'domain_id'		=> $domain_id,
									'user_id'		=> Auth::getID()
								]);
								
								if($domain !== false && $domain->id >= 1) {
									$deletion[$domain->id] = $domain->name;
								} else {
									$stop	= true;
									$unable = Database::exists('SELECT * FROM `' . DATABASE_PREFIX . 'domains` WHERE `id`=:domain_id LIMIT 1', [
										'domain_id'	=> $domain_id
									]);
									
									if(empty($unable)) {
										$this->assign('error', I18N::get('An unknown error has occurred. Please retry your action!'));
									} else {
										$this->assign('error', sprintf(I18N::get('You have no permissions to delete the Domain <strong>%s</strong>!'), $unable->name));
									}
									break;
								}
							}
							
							if(!$stop) {
								$domains = [];
								
								foreach($deletion AS $id => $domain) {
									$domains[] = $domain;
									Database::update(DATABASE_PREFIX . 'domains', [ 'id', 'user_id' ], [
										'id'			=> $id,
										'user_id'		=> Auth::getID(),
										'time_deleted'	=> date('Y-m-d H:i:s', time())
									]);
								}
								
								$this->assign('success', sprintf(I18N::get('Following Domains will be deleted: <strong>%s</strong>!'), implode(', ', $domains)));
								$this->domains = Database::fetch('SELECT * FROM `' . DATABASE_PREFIX . 'domains` WHERE `user_id`=:user AND `type`=\'DOMAIN\' ORDER BY `name` ASC', [
									'user'	=> Auth::getID()
								]);
							}
						}
					break;
				}
			}
		}
		
		public function onSave($data = []) : ?string {
			if(!isset($data['domain']) || empty($data['domain'])) {
				return I18N::get('Please enter a domain name!');
			}
			
			// @ToDo validate Domain!!!
			$domain = Database::single('SELECT * FROM `' . DATABASE_PREFIX . 'domains` WHERE `name`=:name AND `user_id`=:user_id LIMIT 1', [
				'name'		=> $data['domain'],
				'user_id'	=> Auth::getID()
			]);
			
			if($domain !== false) {
				return I18N::get('Domain already exists.');
			}
			
			if(!isset($data['type']) || empty($data['type'])) {
				return I18N::get('Please select an valid home directory!');
			}
			
			$directory = '/';
			
			switch($data['type']) {
				case 'new':
					$directory = sprintf('/%s/', $data['domain']);
				break;
				case 'existing':
					if(!isset($data['directory']) || empty($data['directory'])) {
						return I18N::get('Please select an valid home directory!');
					}
					
					if(!file_exists(sprintf('%s%s/%s', HOST_PATH, Auth::getUsername(), $data['directory']))) {
						return I18N::get('Please select an existing home directory!');
					}
					
					$directory = sprintf('%s/', $data['directory']);
				break;
				default:
					return I18N::get('Please select an valid home directory!');
				break;
			}
			
			$id = Database::insert(DATABASE_PREFIX . 'domains', [
				'id'			=> null,
				'user_id'		=> Auth::getID(),
				'name'			=> $data['domain'],
				'directory'		=> $directory,
				'type'			=> 'DOMAIN',
				'active'		=> 'YES',
				'enabled'		=> 'YES',
				'time_created'	=> NULL,
				'time_deleted'	=> NULL
			]);
			
			return true;
		}
		
		public function content() {
			if(empty($this->domains)) {
				require_once('views/empty.php');
			} else {
				require_once('views/list.php');
			}
		}
	}
?>