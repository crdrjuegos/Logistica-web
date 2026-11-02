$taskEa=New-Object -ComObject EA.Repository
$taskModelPath=Join-Path (Get-Location) 'outputs/avance2_2026_10_28/Arquitectura_WMS.eapx'
if(-not (Test-Path -LiteralPath $taskModelPath)){ Copy-Item -LiteralPath 'C:/Program Files (x86)/Sparx Systems/EA Trial/EABase.eapx' -Destination $taskModelPath }
Write-Output "OPEN=$($taskEa.OpenFile($taskModelPath))"
Write-Output "MODELS=$($taskEa.Models.Count)"
$taskEa.CloseFile()
