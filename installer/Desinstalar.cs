using System;
using System.Diagnostics;
using System.IO;
using System.Windows.Forms;

internal static class Program
{
    [STAThread]
    private static int Main()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);

        string root = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
        string ps1 = Path.Combine(root, "scripts", "desinstalar-windows.ps1");
        if (!File.Exists(ps1))
        {
            MessageBox.Show("Não achei scripts\\desinstalar-windows.ps1.", "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        if (MessageBox.Show(
            "Remover o agente, as tarefas e os atalhos deste PC?\nOs dados da loja na pasta do programa não serão apagados.",
            "Desinstalar Wi-Fi da loja",
            MessageBoxButtons.YesNo,
            MessageBoxIcon.Question) != DialogResult.Yes)
        {
            return 0;
        }

        var info = new ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = "-NoProfile -ExecutionPolicy Bypass -File \"" + ps1 + "\"",
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = root
        };

        try
        {
            Process p = Process.Start(info);
            if (p == null)
            {
                return 1;
            }
            p.WaitForExit();
            return p.ExitCode;
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "Wi-Fi da loja", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }
    }
}
