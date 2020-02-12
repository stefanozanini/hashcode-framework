<?php$fileName = 'd';require_once 'reader-topo.php';/** * F U N C T I O N S *//* * $filter [c1, c2, ...] <- only the involved files (calculated by the getInvolvedFiles function) * $t = time of the earliest server free! */function prepareFiles($filterIdFiles = null){    global $files, $preparedFiles, $preparedFilesIndirect;    $preparedFiles = [];    $preparedFilesIndirect = [];    foreach ($files as $file) {        if ($filterIdFiles === null || in_array($file->id, $filterIdFiles))            prepareFile($file);    }    foreach ($files as $file) {        if ($filterIdFiles === null || in_array($file->id, $filterIdFiles))            prepareFileIndirect($file);    }}function prepareFile($file){    global $files, $preparedFiles;    if ($preparedFiles[$file->id])        return;    $preparedFiles[$file->id] = true;    //run    $file->goalPointsOfDependants = 0;    // align dependencies    /** @var File $file */    foreach ($file->dependenciesIds as $depId) {        $depFile = $files[$depId];        prepareFile($depFile);        $depFile->goalPointsOfDependants += $file->goalPoints;        if (!in_array($file->id, $depFile->dependantsIds))            $depFile->dependantsIds[] = $file->id;    }}function prepareFileIndirect($file){    global $preparedFilesIndirect;    if ($preparedFilesIndirect[$file->id])        return;    $preparedFilesIndirect[$file->id] = true;    //run    $file->indirectDependantsIds = getRecursiveIndirectDependants($file->id);    $file->indirectDependenciesIds = getRecursiveIndirectDependencies($file->id);}function getRecursiveIndirectDependants($fileId){    global $files;    $ret = [];    /** @var File $file */    foreach ($files[$fileId]->dependantsIds as $id) {        $ret[] = $id;        foreach (getRecursiveIndirectDependants($id) as $_id) {            if (!in_array($_id, $ret))                $ret[] = $_id;        }    }    return $ret;}function getRecursiveIndirectDependencies($fileId){    global $files;    $ret = [];    /** @var File $file */    foreach ($files[$fileId]->dependenciesIds as $id) {        $ret[] = $id;        foreach (getRecursiveIndirectDependencies($id) as $_id) {            if (!in_array($_id, $ret))                $ret[] = $_id;        }    }    return $ret;}function whenFileReady($serverId, $fileId){    global $servers;    /** @var Server $server */    $server = $servers[$serverId];    return $server->filesAt[$fileId];}function compileFile($serverId, $fileId){    global $OUTPUT;    global $SCORE;    global $earliestServer;    global $servers, $files;    /** @var Server $server */    $server = $servers[$serverId];    /** @var File $file */    $file = $files[$fileId];    $freeAt = $server->freeAt;    foreach ($file->indirectDependenciesIds as $depId) {        $depReadyAt = whenFileReady($serverId, $depId);        if (!isset($depReadyAt))            die("FATAL: depReadyAt ($serverId, $depId) not set. Can't compile!");        $freeAt = max($freeAt, $depReadyAt);    }    $freeAt += $file->compilingTime;    $server->freeAt = $freeAt;    $server->filesAt[$fileId] = $freeAt;    /** @var Server $_server */    foreach ($servers as $_server) {        $_server->filesAt[$fileId] = $_server->filesAt[$fileId] ? min($_server->filesAt[$fileId], $freeAt + $file->replicationTime) : ($freeAt + $file->replicationTime);    }    if (!$file->replicatedAt)        $file->replicatedAt = $freeAt + $file->replicationTime;    $file->readyAt[$serverId] = $freeAt;    if ($file->isTarget && $freeAt <= $file->deadline) {        $score = $file->goalPoints + ($file->deadline - $freeAt);        $SCORE += $score;    }    alignFile($file);    foreach ($file->dependantsIds as $depId)        alignFile($files[$depId]);    foreach ($servers as $s)        if ($s->freeAt < $earliestServer->freeAt)            $earliestServer = $s;    $OUTPUT[] = "$fileId $serverId";}function alignFiles(){    global $files;    foreach ($files as $file)        alignFile($file);}function alignFile($file){    global $files;    $nRemainingDependencies = 0;    /** @var File $file */    foreach ($file->indirectDependenciesIds as $depId)        if (!$files[$depId]->replicatedAt)            $nRemainingDependencies++;    $file->nRemainingDependencies = $nRemainingDependencies;    $nearestStartDeadlineNotExpired = null;    $goalPointsOfIndirectDependants = 0;    foreach ($file->indirectDependantsIds as $depId) {        $goalPointsOfIndirectDependants += $files[$depId]->goalPoints;        if ($files[$depId]->isTarget) {            $dead = $files[$depId]->deadline;            $nearestStartDeadlineNotExpired = $nearestStartDeadlineNotExpired ? min($nearestStartDeadlineNotExpired, $dead) : $dead;        }    }    $file->goalPointsOfIndirectDependants = $goalPointsOfIndirectDependants;    $goalPointsOfDependants = 0;    foreach ($file->dependantsIds as $depId)        $goalPointsOfDependants += $files[$depId]->goalPoints;    $file->goalPointsOfDependants = $goalPointsOfDependants;    $file->nearestStartDeadlineNotExpired = $nearestStartDeadlineNotExpired;}function array_keysort(&$data, $_key, $sort = SORT_DESC){    // Obtain a list of columns    foreach ($data as $key => $row) {        $sorter[$key] = $row[$_key];    }    // Sort the data with volume descending, edition ascending    // Add $data as the last parameter, to sort by the common key    array_multisort($sorter, $sort, $data);}/** * R U N T I M E */$OUTPUT = [];$SCORE = 0;$BESTSCORE = 0;$earliestServer = $servers[0];// heatingprepareFiles();alignFiles();$orderedFiles = [];foreach ($files as $f) {    if ($f->isTarget) {        $totalCompilingTime = 0;        foreach ($f->indirectDependenciesIds as $depId) {            $totalCompilingTime += $files[$depId]->compilingTime + $files[$depId]->replicationTime; // con replication        }        $cipiace = $f->goalPoints + ($f->deadline - $totalCompilingTime / count($servers));        $orderedFiles[] = ['fileId' => $f->id, 'cipiace' => $cipiace];    }}array_keysort($orderedFiles, 'cipiace', SORT_ASC);while (true) {    $first = array_pop($orderedFiles);    $firstFile = $files[$first['fileId']];    $usefulDependencies = $files[$first['fileId']]->indirectDependenciesIds;    while (true) {        $bestScore = 0;        $bestLeaf = null;        $leafs = array_filter($files, function ($f) use ($usefulDependencies) {            /** @var File $f */            return $f->nRemainingDependencies == 0 && !$f->replicatedAt && in_array($f->id, $usefulDependencies);        });        if (count($leafs) == 0)            break;        /** @var File $leaf */        foreach ($leafs as $leaf) {            $score = 0;            /*            if ($leaf->goalPoints > 0)                $score += pow($leaf->goalPoints * 10, 2);            $score += pow($leaf->goalPointsOfDependants, 1.5);            $score += pow($leaf->goalPointsOfIndirectDependants, 1);            */            $score = $leaf->compilingTime;            //$score = -$leaf->nearestStartDeadlineNotExpired;            if ($score > $bestScore) {                $bestScore = $score;                $bestLeaf = $leaf;            }        }        if ($bestLeaf) {            echo "Compiling " . $bestLeaf->id . " on " . $earliestServer->id . " [total leafs = " . count($leafs) . "]\n";            compileFile($earliestServer->id, $bestLeaf->id);            echo "SCORE = $SCORE\n";        }        //die();    }    echo "Compiling " . $firstFile->id . " on " . $earliestServer->id . "\n";    compileFile($earliestServer->id, $firstFile->id);    echo "SCORE = $SCORE\n";    if($SCORE > $BESTSCORE) {        $BESTSCORE = $SCORE;        /** @var FileManager $filaManager */        $fileManager->output(count($OUTPUT) . "\n" . implode("\n", $OUTPUT));    }}