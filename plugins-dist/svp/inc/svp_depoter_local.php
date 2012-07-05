<?php


/**
 * Met a jour les tables paquets et plugins
 * en ce qui concerne les paquets locaux (presents sur le site).
 *
 * On ne met a jour que ce qui a change, sauf si :
 * - $force = true
 * - ou var_mode=vider_paquets_locaux
 * Dans ces cas, toutes les infos locales sont recalculees.
 *
 * @param bool $force
 * 		Forcer les mises a jour des infos en base de tous les paquets locaux
 * @return
**/
function svp_actualiser_paquets_locaux($force = false) {

	spip_timer('paquets_locaux');
	$paquets = svp_descriptions_paquets_locaux();

	// un mode pour tout recalculer sans désinstaller le plugin... !
	if ($force OR _request('var_mode') == 'vider_paquets_locaux') { 
		svp_base_supprimer_paquets_locaux();
		svp_base_inserer_paquets_locaux($paquets);
	} else {
		svp_base_modifier_paquets_locaux($paquets);
	}
	svp_base_actualiser_paquets_actifs();

	$temps = spip_timer('paquets_locaux');
#spip_log('svp_actualiser_paquets_locaux', 'SVP');
#spip_log($temps, 'SVP');
	return "Éxécuté en : " . $temps;
	
}


function svp_descriptions_paquets_locaux() {
	include_spip('inc/plugin');
	liste_plugin_files(_DIR_PLUGINS);
	liste_plugin_files(_DIR_PLUGINS_DIST);
	$get_infos = charger_fonction('get_infos', 'plugins');
	$paquets_locaux = array(
		'_DIR_PLUGINS'    => $get_infos(array(), false, _DIR_PLUGINS),
		'_DIR_PLUGINS_DIST' => $get_infos(array(), false, _DIR_PLUGINS_DIST),
	);
	if (defined('_DIR_PLUGINS_SUPP') and _DIR_PLUGINS_SUPP) {
		liste_plugin_files(_DIR_PLUGINS_SUPP);
		$paquets_locaux['_DIR_PLUGINS_SUPP'] = $get_infos(array(), false, _DIR_PLUGINS_SUPP);
	}
	
	// creer la liste des signatures
	foreach($paquets_locaux as $const_dir => $paquets) {
		foreach ($paquets as $chemin => $paquet) {
			$paquets_locaux[$const_dir][$chemin]['signature'] = md5($const_dir . $chemin . serialize($paquet));
		}
	}
	
	return $paquets_locaux;
}


// supprime les paquets et plugins locaux.
function svp_base_supprimer_paquets_locaux() {
	sql_delete('spip_paquets', 'id_depot = ' . 0); //_paquets locaux en 0
	sql_delete('spip_plugins', sql_in('id_plugin', sql_get_select('DISTINCT(id_plugin)', 'spip_paquets'), 'NOT'));
}


/**
 * Actualise les informations en base
 * sur les paquets locaux
 * en ne modifiant que ce qui a changé.
 *
 * @param array $plugins liste d'identifiant de plugins
**/
function svp_base_modifier_paquets_locaux($paquets_locaux) {
	include_spip('inc/svp_depoter_distant');

	// On ne va modifier QUE les paquets locaux qui ont change
	// Et cela en comparant les md5 des informations fouries.
	$signatures = array();

	// recuperer toutes les signatures 
	foreach($paquets_locaux as $const_dir => $paquets) {
		foreach ($paquets as $chemin => $paquet) {
			$signatures[$paquet['signature']] = array(
				'constante' => $const_dir,
				'chemin'    => $chemin,
				'paquet'    => $paquet,
			);
		}
	}

	// tous les paquets du depot qui ne font pas parti des signatures
	$anciens_paquets = sql_allfetsel('id_paquet', 'spip_paquets', array('id_depot=' . sql_quote(0), sql_in('signature', array_keys($signatures), 'NOT')));
	$anciens_paquets = array_map('array_shift', $anciens_paquets);

	// tous les plugins correspondants aux anciens paquets
	$anciens_plugins = sql_allfetsel('p.id_plugin',	array('spip_plugins AS p', 'spip_paquets AS pa'), array('p.id_plugin=pa.id_plugin', sql_in('pa.id_paquet', $anciens_paquets)));
	$anciens_plugins = array_map('array_shift', $anciens_plugins);

	// suppression des anciens paquets
	sql_delete('spip_paquets', sql_in('id_paquet', $anciens_paquets));
	
	// supprimer les plugins orphelins
	svp_supprimer_plugins_orphelins($anciens_plugins);

	// on ne garde que les paquets qui ne sont pas presents dans la base
	$signatures_base = sql_allfetsel('signature', 'spip_paquets', 'id_depot='.sql_quote(0));
	$signatures_base = array_map('array_shift', $signatures_base);
	$signatures = array_diff_key($signatures, array_flip($signatures_base));

	// on recree la liste des paquets locaux a inserer
	$paquets_locaux = array();
	foreach ($signatures as $s => $infos) {
		if (!isset($paquets_locaux[$infos['constante']])) {
			$paquets_locaux[$infos['constante']] = array();
		}
		$paquets_locaux[$infos['constante']][$infos['chemin']] = $infos['paquet'];
	}

	svp_base_inserer_paquets_locaux($paquets_locaux);
}



function svp_base_inserer_paquets_locaux($paquets_locaux) {
	include_spip('inc/svp_depoter_distant');
	
	// On initialise les informations specifiques au paquet :
	// l'id du depot et les infos de l'archive
	$paquet_base = array(
		'id_depot' => 0,
		'nom_archive' => '',
		'nbo_archive' => '',
		'maj_archive' => '',
		'src_archive' => '',
		'date_modif' => '',
		'maj_version' => '',
		'signature' => '',
	);

	$preparer_sql_paquet = charger_fonction('preparer_sql_paquet', 'plugins');

	// pour chaque decouverte, on insere les paquets en base.
	// on evite des requetes individuelles, tres couteuses en sqlite...
	$cle_plugins    = array(); // prefixe => id
	$insert_plugins = array(); // insertion prefixe...
	$insert_plugins_vmax = array(); // vmax des nouveaux plugins...
	$insert_paquets = array(); // insertion de paquet...

	include_spip('inc/config');
	$recents = lire_config('plugins_interessants');
	$installes  = lire_config('plugin_installes');
	$actifs  = lire_config('plugin');
	$attentes  = lire_config('plugin_attente');

	foreach($paquets_locaux as $const_dir => $paquets) {
		foreach ($paquets as $chemin => $paquet) {
			// Si on est en presence d'un plugin dont la dtd est "paquet" on compile en multi
			// les nom, slogan et description a partir des fichiers de langue.
			// De cette façon, les informations des plugins locaux et distants seront identiques
			// => On evite l'utilisation de _T() dans les squelettes
			if ($paquet['dtd'] == 'paquet') {
				$multis = svp_compiler_multis($paquet['prefix'], constant($const_dir) . '/' . $chemin);
				if (isset($multis['nom']))
					$paquet['nom'] = $multis['nom'];
				$paquet['slogan'] = (isset($multis['slogan'])) ? $multis['slogan'] : '';
				$paquet['description'] = (isset($multis['description'])) ? $multis['description'] : '';
			}

			$le_paquet = $paquet_base;
			#$le_paquet['traductions'] = serialize($paquet['traductions']);

			if ($champs = $preparer_sql_paquet($paquet)) {

				// Eclater les champs recuperes en deux sous tableaux, un par table (plugin, paquet)
				$champs = eclater_plugin_paquet($champs);
				$paquet_plugin = true;
				
				// On complete les informations du paquet et du plugin
				$le_paquet = array_merge($le_paquet, $champs['paquet']);
				$le_plugin = $champs['plugin'];

				// On loge l'absence de categorie ou une categorie erronee et on positionne la categorie par defaut "aucune"
				if (!$le_plugin['categorie']) {
					$le_plugin['categorie'] = 'aucune';
				} else {
					if (!in_array($le_plugin['categorie'], $GLOBALS['categories_plugin'])) {
						$le_plugin['categorie'] = 'aucune';
					}
				}

				// creation du plugin...
				$prefixe = strtoupper( $le_plugin['prefixe'] );
				// on fait attention lorqu'on cherche ou ajoute un plugin
				// le nom et slogan est TOUJOURS celui de la plus haute version
				// et il faut donc possiblement mettre a jour la base...
				// 
				// + on est tolerant avec les versions identiques de plugin deja presentes
				//   on permet le recalculer le titre...
				if (!isset($cle_plugins[$prefixe])) {
					if (!$res = sql_fetsel('id_plugin, vmax', 'spip_plugins', 'prefixe = '.sql_quote($prefixe))) {
						// on ne stocke pas de vmax pour les plugins locaux dans la bdd... (parait il)
						if (!isset($insert_plugins[$prefixe])) {
							$insert_plugins[$prefixe] = $le_plugin;
							$insert_plugins_vmax[$prefixe] = $le_paquet['version'];
						} elseif (spip_version_compare($le_paquet['version'], $insert_plugins_vmax[$prefixe], '>')) {
							$insert_plugins[$prefixe] = $le_plugin;
							$insert_plugins_vmax[$prefixe] = $le_paquet['version'];
						}
					} else {
						$id_plugin = $res['id_plugin'];
						$cle_plugins[$prefixe] = $id_plugin;
						// comme justement on ne stocke pas de vmax pour les plugins locaux...
						// il est possible que ce test soit faux. pff.
						if (spip_version_compare($le_paquet['version'], $res['vmax'], '>=')) {
							sql_updateq('spip_plugins', $le_plugin, 'id_plugin='.sql_quote($id_plugin));
						}
					}
				}

				// ajout du prefixe dans le paquet
				$le_paquet['prefixe']     = $prefixe;
				$le_paquet['constante']   = $const_dir;
				$le_paquet['src_archive'] = $chemin;
				$le_paquet['recent']      = isset($recents[$chemin]) ? $recents[$chemin] : 0;
				$le_paquet['installe']    = in_array($chemin, $installes) ? 'oui': 'non'; // est desinstallable ?
				$le_paquet['obsolete']    = 'non';
				$le_paquet['signature']   = $paquet['signature'];

				// le plugin est il actuellement actif ?
				$actif = "non";
				if (isset($actifs[$prefixe])
					and ($actifs[$prefixe]['dir_type'] == $const_dir)
					and ($actifs[$prefixe]['dir'] == $chemin)) {
					$actif = "oui";
				}
				$le_paquet['actif'] = $actif;

				// le plugin etait il actif mais temporairement desactive
				// parce qu'une dependence a disparue ?
				$attente = "non";
				if (isset($attentes[$prefixe])
					and ($attentes[$prefixe]['dir_type'] == $const_dir)
					and ($attentes[$prefixe]['dir'] == $chemin)) {
					$attente = "oui";
					$le_paquet['actif'] = "oui"; // il est presenté dans la liste des actifs (en erreur).
				}
				$le_paquet['attente'] = $attente;

				// on recherche d'eventuelle mises a jour existantes
				if ($maj_version = svp_rechercher_maj_version($prefixe, $le_paquet['version'], $le_paquet['etatnum'])) {
					$le_paquet['maj_version'] = $maj_version;
				}

				$insert_paquets[] = $le_paquet;
			}
		}
	}

	if ($insert_plugins) {
		sql_insertq_multi('spip_plugins', $insert_plugins);
		$pls = sql_allfetsel(array('id_plugin', 'prefixe'), 'spip_plugins', sql_in('prefixe', array_keys($insert_plugins)));
		foreach ($pls as $p) {
			$cle_plugins[$p['prefixe']] = $p['id_plugin'];
		}
	}
	
	if ($insert_paquets) {

		// sert pour le calcul d'obsolescence
		$id_plugin_concernes = array();
		
		foreach ($insert_paquets as $c => $p) {
			$insert_paquets[$c]['id_plugin'] = $cle_plugins[$p['prefixe']];
			$id_plugin_concernes[ $insert_paquets[$c]['id_plugin'] ] = true;

			// remettre les necessite, utilise, librairie dans la cle 0
			// comme SVP
			if ($dep = unserialize($insert_paquets[$c]['dependances']) and is_array($dep)) {
				foreach ($dep as $d => $contenu) {
					if ($contenu) {
						$new = array();
						foreach($contenu as $n) {
							unset($n['id']);
							$new[ strtolower($n['nom']) ] = $n;
						}
						$dep[$d] = array($new);
					}
				}
				$insert_paquets[$c]['dependances'] = serialize($dep);
			}

		}

		sql_insertq_multi('spip_paquets', $insert_paquets);

		svp_corriger_obsolete_paquets( array_keys($id_plugin_concernes) );
	}
}


/**
 * Fait correspondre l'état des métas des plugins actifs & installés
 * avec ceux en base de données dans spip_paquets pour le dépot local 
**/
function svp_base_actualiser_paquets_actifs() {
	$installes  = lire_config('plugin_installes');
	$actifs  = lire_config('plugin');
	$attentes  = lire_config('plugin_attente');

	$locaux = sql_allfetsel(
		array('id_paquet', 'prefixe', 'actif', 'installe', 'attente', 'constante', 'src_archive'),
		'spip_paquets',
		'id_depot='.sql_quote(0));
	$changements = array();

	foreach ($locaux as $l) {
		$copie = $l;
		$prefixe = strtoupper($l['prefixe']);
		// actif ?
		if (isset($actifs[$prefixe])
			and ($actifs[$prefixe]['dir_type'] == $l['constante'])
			and ($actifs[$prefixe]['dir'] == $l['src_archive'])) {
			$copie['actif'] = "oui";
		} else {
			$copie['actif'] = "non";
		}
		
		// attente ?
		if (isset($attentes[$prefixe])
			and ($attentes[$prefixe]['dir_type'] == $l['constante'])
			and ($attentes[$prefixe]['dir'] == $l['src_archive'])) {
			$copie['attente'] = "oui";
			$copie['actif'] = "oui"; // il est presente dans la liste des actifs (en erreur). 
		} else {
			$copie['attente'] = "non";
		}
		
		// installe ?
		if (in_array($l['src_archive'], $installes)) {
			$copie['installe'] = "oui";
		} else {
			$copie['installe'] = "non";
		}

		if ($copie != $l) {
			$changements[ $l['id_paquet'] ] = array(
				'actif'    => $copie['actif'],
				'installe' => $copie['installe'],
				'attente'  => $copie['attente'] );
		}
	}

	if (count($changements)) {
		// On insere, en encapsulant pour sqlite...
		if (sql_preferer_transaction()) {
			sql_demarrer_transaction();
		}

		foreach ($changements as $id_paquet => $data) {
			sql_updateq('spip_paquets', $data, 'id_paquet=' . intval($id_paquet));
		}

		if (sql_preferer_transaction()) {
			sql_terminer_transaction();
		}
	}

}

// Construit le contenu multi des balises nom, slogan et description a partir des items de langue
// contenus dans les fichiers paquet-prefixe_langue.php
function svp_compiler_multis($prefixe, $dir_source) {

	$multis =array();
	// ici on cherche le fichier et les cles avec un prefixe en minuscule systematiquement...
	$prefixe = strtolower($prefixe);
	$module = "paquet-$prefixe";
	$item_nom = $prefixe . "_nom";
	$item_slogan = $prefixe . "_slogan";
	$item_description = $prefixe . "_description";

	// On cherche tous les fichiers de langue destines a la traduction du paquet.xml
	if ($fichiers_langue = glob($dir_source . "/lang/{$module}_*.php")) {
		$nom = $slogan = $description = '';
		foreach ($fichiers_langue as $_fichier_langue) {
			$nom_fichier = basename($_fichier_langue, '.php');
			$langue = substr($nom_fichier, strlen($module) + 1 - strlen($nom_fichier));
			// Si la langue est reconnue, on traite la liste des items de langue
			if (isset($GLOBALS['codes_langues'][$langue])) {
				$GLOBALS['idx_lang'] = $langue;
				include($_fichier_langue);
				foreach ($GLOBALS[$langue] as $_item => $_traduction) {
					if ($_traduction = trim($_traduction)) {
						if ($_item == $item_nom)
							$nom .= "[$langue]$_traduction";
						if ($_item == $item_slogan)
							$slogan .= "[$langue]$_traduction";
						if ($_item == $item_description)
							$description .= "[$langue]$_traduction";
					}
				}
			}
		}

		// Finaliser la construction des balises multi
		if ($nom) $multis['nom'] = "<multi>$nom</multi>";
		if ($slogan) $multis['slogan'] = "<multi>$slogan</multi>";
		if ($description) $multis['description'] = "<multi>$description</multi>";
	}

	return $multis;
}


/**
 * Met à jour les informations d'obsolescence
 * des paquets locaux.
 *
 * @param array $ids_plugin
 * 		Identifiant de plugins concernes par les mises a jour
 * 		En cas d'absence, passera sur tous les paquets locaux
**/
function svp_corriger_obsolete_paquets($ids_plugin = array()) {
	// on minimise au maximum le nombre de requetes.
	// 1 pour lister les paquets
	// 1 pour mettre à jour les obsoletes à oui
	// 1 pour mettre à jour les obsoletes à non

	$where = array('pa.id_plugin = pl.id_plugin', 'id_depot='.sql_quote(0));
	if ($ids_plugin) {
		$where[] = sql_in('pl.id_plugin', $ids_plugin);
	}
	
	// comme l'on a de nouveaux paquets locaux...
	// certains sont peut etre devenus obsoletes
	// parmis tous les plugins locaux presents
	// concernes par les memes prefixes que les plugins ajoutes.
	$obsoletes = array();
	$changements = array();
	
	$paquets = sql_allfetsel(
		array('pa.id_paquet', 'pl.prefixe', 'pa.version', 'pa.etatnum', 'pa.obsolete'),
		array('spip_paquets AS pa', 'spip_plugins AS pl'),
		$where);

	foreach ($paquets as $c => $p) {

		$obsoletes[$p['prefixe']][] = $c;

		// si 2 paquet locaux ont le meme prefixe, mais pas la meme version,
		// l'un est obsolete : la version la plus ancienne
		// Si version et etat sont egaux, on ne decide pas d'obsolescence.
		if (count($obsoletes[$p['prefixe']]) > 1) {
			foreach ($obsoletes[$p['prefixe']] as $cle) {
				if ($cle == $c) continue;

				// je suis plus petit qu'un autre
				if (spip_version_compare($paquets[$c]['version'], $paquets[$cle]['version'], '<')) {
					if ($paquets[$c]['etatnum'] <= $paquets[$cle]['etatnum']) {
						if ($paquets[$c]['obsolete'] != 'oui') {
							$paquets[$c]['obsolete'] = 'oui';
							$changements[$c] = true;
						}
					}
				}

				// je suis plus grand ou egal a un autre...
				else {
					// je suis plus strictement plus grand a un autre...
					if (spip_version_compare($paquets[$c]['version'], $paquets[$cle]['version'], '>')) {
						// si mon etat est meilleur, rendre obsolete les autres
						if ($paquets[$c]['etatnum'] >= $paquets[$cle]['etatnum']) {
								if ($paquets[$cle]['obsolete'] != 'oui') {
									$paquets[$cle]['obsolete'] = 'oui';
									$changements[$cle] = true;
								}
						}
					}

					// je suis egal a un autre
					// si mon etat est strictement meilleur, rendre obsolete les autres
					elseif ($paquets[$c]['etatnum'] > $paquets[$cle]['etatnum']) {
							if ($paquets[$cle]['obsolete'] != 'oui') {
								$paquets[$cle]['obsolete'] = 'oui';
								$changements[$cle] = true;
							}
					}
				}

			}
		} else {
			if ($paquets[$c]['obsolete'] != 'non') {
				$paquets[$c]['obsolete'] = 'non';
				$changements[$c] = true;
			}
		}
	}

	if (count($changements)) {
		$oui = $non = array();
		foreach ($changements as $c => $null) {
			if ($paquets[$c]['obsolete'] == 'oui') {
				$oui[] = $paquets[$c]['id_paquet'];
			} else {
				$non[] = $paquets[$c]['id_paquet'];
			}
		}

		if ($oui) {
			sql_updateq('spip_paquets', array('obsolete'=>'oui'), sql_in('id_paquet', $oui));
		}
		if ($non) {
			sql_updateq('spip_paquets', array('obsolete'=>'non'), sql_in('id_paquet', $non));
		}
	}
}




/**
 * Supprime les plugins devenus orphelins dans cette liste.
 *
 * @param array $plugins liste d'identifiant de plugins
**/
function svp_supprimer_plugins_orphelins($plugins) {
	// tous les plugins encore lies a des depots...
	if ($plugins) {
		$p = sql_allfetsel('DISTINCT(p.id_plugin)', array('spip_plugins AS p', 'spip_paquets AS pa'), array(sql_in('p.id_plugin', $plugins), 'p.id_plugin=pa.id_plugin'));
		$p = array_map('array_shift', $p);
		$diff = array_diff($plugins, $p);
		// pour chaque plugin non encore utilise, on les vire !
		sql_delete('spip_plugins', sql_in('id_plugin', $diff));
		return $p; // les plugins encore en vie !
	}
}


/**
 * Cherche dans les dépots distant
 * un plugin qui serait plus à jour que le prefixe, version et état que l'on transmet 
 *
 * @param string $prefixe
 * 		Préfixe du plugin
 * @param string $version
 * 		Version du paquet a comparer
 * @param int $etatnum
 * 		État du paquet numérique
 * @return string
 * 		Version plus a jour, sinon rien
**/
function svp_rechercher_maj_version($prefixe, $version, $etatnum) {

	$maj_version = "";

	if ($res = sql_allfetsel(
		array('pl.id_plugin', 'pa.version'),
		array('spip_plugins AS pl', 'spip_paquets AS pa'),
		array(
			'pl.id_plugin = pa.id_plugin',
			'pa.id_depot>' . sql_quote(0),
			'pl.prefixe=' . sql_quote($prefixe),
			'pa.etatnum>=' . sql_quote($etatnum))))
		{

		foreach ($res as $paquet_distant) {
			// si version superieure et etat identique ou meilleur,
			// c'est que c'est une mise a jour possible !
			if (spip_version_compare($paquet_distant['version'],$version,'>')) {
				if (!strlen($maj_version) or spip_version_compare($paquet_distant['version'], $maj_version, '>')) {
					$maj_version = $paquet_distant['version'];
				}
				# a voir si on utilisera...
				# "superieur"		=> "varchar(3) DEFAULT 'non' NOT NULL",
				# // superieur : version plus recente disponible (distant) d'un plugin (actif?) existant
			}
		}
	}

	return $maj_version;
}




/**
 * Actualise maj_version pour tous les paquets locaux
 * 
**/
function svp_actualiser_maj_version() {
	$update = array();
	// tous les paquets locaux
	if ($locaux = sql_allfetsel(
		array('id_paquet', 'prefixe', 'version', 'maj_version', 'etatnum'),
		array('spip_paquets'),
		array('pa.id_depot=' . sql_quote(0))))
	{
		foreach ($locaux as $paquet) {
			$new_maj_version = svp_rechercher_maj_version($paquet['prefixe'], $paquet['version'], $paquet['etatnum']);
			if ($new_maj_version != $paquet['maj_version']) {
				$update[$paquet['id_paquet']] = array('maj_version' => $new_maj_version);
			}
		}
	}
	if ($update) {
		// On insere, en encapsulant pour sqlite...
		if (sql_preferer_transaction()) {
			sql_demarrer_transaction();
		}

		foreach ($update as $id_paquet => $data) {
			sql_updateq('spip_paquets', $data, 'id_paquet=' . intval($id_paquet));
		}

		if (sql_preferer_transaction()) {
			sql_terminer_transaction();
		}
	}
}

?>
