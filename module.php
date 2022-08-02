<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2022 webtrees development team
 *					  <http://webtrees.net>
 *
 * Fancy Research Links (webtrees custom module):  
 * Copyright (C) 2022 Carmen Just
 *					  <https://justcarmen.nl>
 *
 * Extended Relationships (webtrees custom module):  
 * Copyright (C) 2022 Richard Cissee
 *					  <http://cissee.de>
 * 
 * RepositoryHierarchy (webtrees custom module):  
 * Copyright (C) 2022 Markus Hemprich
 *                    <http://www.familienforschung-hemprich.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
 

declare(strict_types=1);

namespace Jefferson49\Webtrees\Module\RepositoryHierarchyNamespace;

require __DIR__ . '/RepositoryHierarchy.php';
require __DIR__ . '/CallNumberCategory.php';
require __DIR__ . '/RepositoryHierarchyHelpTexts.php';
require __DIR__ . '/CallNumberFixAction.php';
require __DIR__ . '/CreateSourceModalAction.php';
require __DIR__ . '/DownloadEADxmlService.php';

return new RepositoryHierarchy();
