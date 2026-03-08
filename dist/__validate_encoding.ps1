$ErrorActionPreference='Stop'
$root='zegger-erp'
$textExt=@('.php','.js','.css','.html','.md','.txt','.svg','.json','.xml','.csv')
$utf8Strict = New-Object System.Text.UTF8Encoding($false,$true)
$mojiRegex='(\u00E2\u20AC|\u00C3\u00B3|\u0139\u201A|\u0139\u203A|\u0139\u00BA|\u00C3|\u00C2|\u00C4|\u00C5|\u0102|\u0139)'
$rootFull=(Get-Item $root).FullName
$total=0
$nonUtf8=@()
$phpBom=@()
$moji=@()
Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
  $ext=$_.Extension.ToLowerInvariant()
  if(-not ($textExt -contains $ext)){ return }
  $total++
  $bytes=[System.IO.File]::ReadAllBytes($_.FullName)
  if($ext -eq '.php' -and $bytes.Length -ge 3 -and $bytes[0]-eq 0xEF -and $bytes[1]-eq 0xBB -and $bytes[2]-eq 0xBF){
    $phpBom += $_.FullName.Substring($rootFull.Length+1).Replace('\\','/')
  }
  try {
    $text=$utf8Strict.GetString($bytes)
  } catch {
    $nonUtf8 += $_.FullName.Substring($rootFull.Length+1).Replace('\\','/')
    return
  }
  if([regex]::IsMatch($text,$mojiRegex)){
    $moji += $_.FullName.Substring($rootFull.Length+1).Replace('\\','/')
  }
}
'TEXT_FILES_TOTAL=' + $total
'NON_UTF8_COUNT=' + $nonUtf8.Count
if($nonUtf8.Count -gt 0){ 'NON_UTF8_FILES_BEGIN'; $nonUtf8; 'NON_UTF8_FILES_END' }
'PHP_BOM_COUNT=' + $phpBom.Count
if($phpBom.Count -gt 0){ 'PHP_BOM_FILES_BEGIN'; $phpBom; 'PHP_BOM_FILES_END' }
'MOJIBAKE_COUNT=' + $moji.Count
if($moji.Count -gt 0){ 'MOJIBAKE_FILES_BEGIN'; $moji; 'MOJIBAKE_FILES_END' }