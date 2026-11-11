' Starts the local Laravel API (if needed) and the Python desktop tracker without console windows.
Option Explicit
Dim fso, shell, root, web, pythonw, probe
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

root = fso.GetParentFolderName(fso.GetParentFolderName(WScript.ScriptFullName))
web = fso.BuildPath(root, "web")

' If the API is not answering, start the local Laravel development server.
probe = ""
On Error Resume Next
probe = shell.Run("cmd /c curl -s -o NUL -w ""%{http_code}"" http://127.0.0.1:8000/up", 0, True)
On Error GoTo 0

If probe <> "0" Then
    shell.Run "cmd /c cd /d """ & web & """ && php artisan serve --host=127.0.0.1 --port=8000", 0, False
    WScript.Sleep 2500
End If

pythonw = "pythonw.exe"
shell.Run """" & pythonw & """ """ & fso.BuildPath(root, "desktop\main.py") & """", 1, False
