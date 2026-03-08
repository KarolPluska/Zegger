$ErrorActionPreference='Stop'
$root='zegger-erp'
$textExt=@('.php','.js','.css','.html','.md','.txt','.svg','.json','.xml','.csv')
$utf8Strict = New-Object System.Text.UTF8Encoding($false,$true)
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$cp1250 = [System.Text.Encoding]::GetEncoding(1250)
$mojiRegex='(\u00C3|\u00C2|\u00C4|\u00C5|\u0102|\u0139|\u00E2\u20AC|\uFFFD)'
$polishRegex='[\u0105\u0107\u0119\u0142\u0144\u00F3\u015B\u017A\u017C\u0104\u0106\u0118\u0141\u0143\u00D3\u015A\u0179\u017B]'

function Get-Score([string]$s,[string]$mojiRegex,[string]$polishRegex){
  $m=[regex]::Matches($s,$mojiRegex).Count
  $p=[regex]::Matches($s,$polishRegex).Count
  return [pscustomobject]@{Moji=$m;Polish=$p}
}

$rootFull=(Get-Item $root).FullName
$modified=@()

Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
  $ext=$_.Extension.ToLowerInvariant()
  if(-not ($textExt -contains $ext)){ return }

  $full=$_.FullName
  $rel=$full.Substring($rootFull.Length+1).Replace('\\','/')
  $bytes=[System.IO.File]::ReadAllBytes($full)
  $hasBom=($bytes.Length -ge 3 -and $bytes[0]-eq 0xEF -and $bytes[1]-eq 0xBB -and $bytes[2]-eq 0xBF)

  $nonUtf8=$false
  $text=''
  try { $text=$utf8Strict.GetString($bytes) } catch { $nonUtf8=$true; $text=$cp1250.GetString($bytes) }

  $orig=$text
  $origScore=Get-Score $orig $mojiRegex $polishRegex

  $best=$orig
  $bestScore=$origScore

  $cand=$orig
  for($i=0; $i -lt 3; $i++){
    $cand=[System.Text.Encoding]::UTF8.GetString($cp1250.GetBytes($cand))
    $score=Get-Score $cand $mojiRegex $polishRegex
    $isBetter=($score.Moji -lt $bestScore.Moji) -or (($score.Moji -eq $bestScore.Moji) -and ($score.Polish -gt $bestScore.Polish))
    if($isBetter){ $best=$cand; $bestScore=$score }
  }

  $applyFix=$false
  if($origScore.Moji -gt 0 -and $bestScore.Moji -lt $origScore.Moji){
    $applyFix=$true
    $text=$best
  }

  $needWrite=$applyFix -or $hasBom -or $nonUtf8
  if($needWrite){
    [System.IO.File]::WriteAllText($full,$text,$utf8NoBom)
    $reasons=@()
    if($applyFix){ $reasons += ('mojibake:' + $origScore.Moji + '->' + $bestScore.Moji) }
    if($hasBom){ $reasons += 'bom_removed' }
    if($nonUtf8){ $reasons += 'converted_to_utf8' }
    $modified += [pscustomobject]@{ File=$rel; Reasons=($reasons -join ',') }
  }
}

'MODIFIED_COUNT=' + $modified.Count
$modified | Sort-Object File | ForEach-Object { $_.File + ' | ' + $_.Reasons }