$ErrorActionPreference='Stop'
$root='zegger-erp'
$textExt=@('.php','.js','.css','.html','.md','.txt','.svg','.json','.xml','.csv')
$utf8Strict = New-Object System.Text.UTF8Encoding($false,$true)
$mojiRegex='(\u00C3|\u00C2|\u00C4|\u00C5|\u0102|\u0139|\u00E2\u20AC)'
$rows=@()
$rootFull=(Get-Item $root).FullName
Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
  $ext=$_.Extension.ToLowerInvariant()
  if(-not ($textExt -contains $ext)){ return }
  $bytes=[System.IO.File]::ReadAllBytes($_.FullName)
  $hasBom=($bytes.Length -ge 3 -and $bytes[0]-eq 0xEF -and $bytes[1]-eq 0xBB -and $bytes[2]-eq 0xBF)
  $utf8Ok=$true
  $text=''
  try { $text=$utf8Strict.GetString($bytes) } catch { $utf8Ok=$false; $text=[System.Text.Encoding]::GetEncoding(1250).GetString($bytes) }
  $moji=[regex]::Matches($text,$mojiRegex).Count
  if($hasBom -or -not $utf8Ok -or $moji -gt 0){
    $rows += [pscustomobject]@{
      File=$_.FullName.Substring($rootFull.Length+1).Replace('\\','/')
      Ext=$ext
      HasBom=$hasBom
      Utf8Ok=$utf8Ok
      Moji=$moji
    }
  }
}
'TOTAL_ISSUE_FILES=' + $rows.Count
'PHP_BOM_FILES=' + (@($rows | Where-Object { $_.Ext -eq '.php' -and $_.HasBom }).Count)
'NON_UTF8_FILES=' + (@($rows | Where-Object { -not $_.Utf8Ok }).Count)
'MOJI_FILES=' + (@($rows | Where-Object { $_.Moji -gt 0 }).Count)
$rows | Sort-Object Moji -Descending | Select-Object -First 120 | ForEach-Object { '{0} | BOM={1} UTF8={2} MOJI={3}' -f $_.File,$_.HasBom,$_.Utf8Ok,$_.Moji }