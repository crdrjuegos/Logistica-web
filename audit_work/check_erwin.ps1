$taskApi = New-Object -ComObject AllFusionERwin.SCAPI
Write-Output "ERWIN_API=$($taskApi.Application.Version)"
Write-Output "UNITS=$($taskApi.PersistenceUnits.Count)"
