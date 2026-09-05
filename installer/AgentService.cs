using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Management;
using System.Net;
using System.Net.NetworkInformation;
using System.Reflection;
using System.ServiceProcess;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;

internal static class AgentProgram
{
    private static void Main(string[] args)
    {
        if (args != null && args.Length > 0)
        {
            string mode = (args[0] ?? "").Trim().ToLowerInvariant();
            if (mode == "--console")
            {
                AgentEngine.Instance.RunBlocking();
                return;
            }
            if (mode == "--session-worker")
            {
                SessionWorker.Run();
                return;
            }
            if (mode == "--install-service")
            {
                ServiceInstallerHelper.Install();
                return;
            }
            if (mode == "--uninstall-service")
            {
                ServiceInstallerHelper.Uninstall();
                return;
            }
        }
        ServiceBase.Run(new AgentWindowsService());
    }
}

internal sealed class AgentWindowsService : ServiceBase
{
    public AgentWindowsService()
    {
        ServiceName = AgentConstants.ServiceName;
        CanStop = true;
        CanShutdown = true;
        AutoLog = true;
    }

    protected override void OnStart(string[] args)
    {
        AgentEngine.Instance.Start();
    }

    protected override void OnStop()
    {
        AgentEngine.Instance.Stop();
    }

    protected override void OnShutdown()
    {
        AgentEngine.Instance.Stop();
    }
}

internal static class AgentConstants
{
    public const string ServiceName = "WiFiDaLojaAgent";
    public const string PipeName = "WiFiDaLojaAgent";
    public const int MaxClientsDefault = 8;
    public const int SyncIntervalSeconds = 5;
    public const int LoopIntervalMs = 1000;
}

internal static class AgentPaths
{
    public static string InstallRoot { get; private set; }
    public static string Storage { get; private set; }
    public static string StatusFile { get; private set; }
    public static string AuthFile { get; private set; }
    public static string ConfigFile { get; private set; }
    public static string CloudFile { get; private set; }
    public static string CommandsDir { get; private set; }
    public static string ProcessedFile { get; private set; }
    public static string LogDir { get; private set; }
    public static string DnsProxyExe { get; private set; }
    public static string CaptiveHttpExe { get; private set; }
    public static string AgentVersion { get; private set; }

    public static void Init()
    {
        InstallRoot = AppDomain.CurrentDomain.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        Storage = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "WiFiDaLoja");
        Directory.CreateDirectory(Storage);
        StatusFile = Path.Combine(Storage, "status.json");
        AuthFile = Path.Combine(Storage, "authorized.json");
        ConfigFile = Path.Combine(Storage, "agent-config.json");
        CloudFile = Path.Combine(Storage, "cloud.json");
        CommandsDir = Path.Combine(Storage, "commands");
        ProcessedFile = Path.Combine(Storage, "commands", "processed.json");
        LogDir = Path.Combine(Storage, "logs");
        DnsProxyExe = Path.Combine(InstallRoot, "DnsProxy.exe");
        CaptiveHttpExe = Path.Combine(InstallRoot, "CaptiveHttp.exe");
        Directory.CreateDirectory(CommandsDir);
        Directory.CreateDirectory(LogDir);
        AgentVersion = ReadVersion();
    }

    private static string ReadVersion()
    {
        try
        {
            string path = Path.Combine(InstallRoot, "AGENT_VERSION");
            if (File.Exists(path))
            {
                return File.ReadAllText(path).Trim();
            }
        }
        catch
        {
        }
        return "2.0.0";
    }
}

internal sealed class AgentEngine
{
    public static readonly AgentEngine Instance = new AgentEngine();
    private readonly object _gate = new object();
    private Thread _thread;
    private volatile bool _running;
    private readonly AgentStateMachine _state = new AgentStateMachine();
    private readonly AgentLog _log = new AgentLog();
    private readonly ProcessSupervisor _supervisor = new ProcessSupervisor();
    private readonly CloudSync _sync = new CloudSync();
    private readonly CommandQueue _commands = new CommandQueue();
    private readonly NamedPipeServer _pipe = new NamedPipeServer();
    private readonly HotspotController _hotspot = new HotspotController();
    private readonly AdapterCatalog _adapters = new AdapterCatalog();
    private AgentRuntimeConfig _config = new AgentRuntimeConfig();
    private int _loopTick;
    private string _lastAck = "";
    private string _lastError;
    private bool _serviceAllowed = true;
    private int _maxClients = AgentConstants.MaxClientsDefault;
    private DateTime _runningSince = DateTime.MinValue;
    private int _recoveryAttempts;

    public void Start()
    {
        lock (_gate)
        {
            if (_running)
            {
                return;
            }
            AgentPaths.Init();
            _running = true;
            _pipe.Start(this);
            _thread = new Thread(RunLoop) { IsBackground = true, Name = "WiFiDaLojaAgent" };
            _thread.Start();
            _log.Info("Agente v" + AgentPaths.AgentVersion + " servico iniciado PID " + Process.GetCurrentProcess().Id);
        }
    }

    public void Stop()
    {
        lock (_gate)
        {
            if (!_running)
            {
                return;
            }
            _running = false;
            _pipe.Stop();
            try
            {
                _hotspot.StopHotspot(_supervisor, _log);
            }
            catch
            {
            }
            _supervisor.StopAll();
            _log.Info("Agente encerrado");
        }
    }

    public void RunBlocking()
    {
        AgentPaths.Init();
        _running = true;
        _pipe.Start(this);
        RunLoop();
    }

    private void RunLoop()
    {
        while (_running)
        {
            try
            {
                Tick();
            }
            catch (Exception ex)
            {
                _log.Error("Loop: " + ex.Message);
            }
            Thread.Sleep(AgentConstants.LoopIntervalMs);
        }
    }

    private void Tick()
    {
        _loopTick++;
        _config = AgentConfigStore.Load();
        _commands.ProcessPending(this);
        _supervisor.Watchdog(_log);
        TryAutoRecovery();
        if (_loopTick % AgentConstants.SyncIntervalSeconds == 0)
        {
            _sync.Run(this);
        }
        WriteStatusSnapshot();
    }

    private void TryAutoRecovery()
    {
        if (_state.Current != HotspotState.Running)
        {
            _recoveryAttempts = 0;
            return;
        }
        if (_hotspot.IsOperational())
        {
            _recoveryAttempts = 0;
            return;
        }
        if (_runningSince == DateTime.MinValue || (DateTime.Now - _runningSince).TotalSeconds < 30)
        {
            return;
        }
        if (_recoveryAttempts >= 3)
        {
            return;
        }
        _recoveryAttempts++;
        _log.Warn("Auto-recovery: hotspot caiu, tentativa " + _recoveryAttempts);
        try
        {
            _hotspot.RestartIcs();
            ExecuteStart(false);
        }
        catch (Exception ex)
        {
            _lastError = ex.Message;
            _log.Error("Auto-recovery falhou: " + ex.Message);
        }
    }

    public string EnqueueCommand(string action, string sourceId)
    {
        return _commands.Enqueue(action, sourceId);
    }

    public void SetWifiAdapterGuid(string guid)
    {
        var cfg = AgentConfigStore.Load();
        cfg.WifiAdapterGuid = guid ?? "";
        AgentConfigStore.Save(cfg);
        _config = cfg;
        _log.Info("Adaptador Wi-Fi selecionado: " + (string.IsNullOrEmpty(guid) ? "automatico" : guid));
        _commands.Enqueue("apply", null);
    }

    public List<Dictionary<string, object>> ListAdaptersForUi()
    {
        return _adapters.ListWifiAdapters().Select(a =>
        {
            var d = new Dictionary<string, object>();
            d["guid"] = a.Guid ?? "";
            d["name"] = a.Name ?? "";
            d["desc"] = a.Description ?? "";
            d["recommended"] = a.Recommended;
            return d;
        }).ToList();
    }

    public void HandleCommand(string action, string cmdId)
    {
        _lastError = null;
        try
        {
            switch ((action ?? "").Trim().ToLowerInvariant())
            {
                case "start":
                    ExecuteStart(false);
                    break;
                case "stop":
                    ExecuteStop();
                    break;
                case "apply":
                    ExecuteStart(true);
                    break;
                default:
                    throw new InvalidOperationException("Comando desconhecido: " + action);
            }
            _lastAck = cmdId ?? "";
            _log.Info("Comando concluido: " + action);
        }
        catch (Exception ex)
        {
            _lastError = ex.Message;
            _state.SetError(ex.Message);
            _log.Error("Comando " + action + ": " + ex.Message);
        }
    }

    private void ExecuteStart(bool applyOnly)
    {
        if (!_serviceAllowed)
        {
            throw new InvalidOperationException("Servico suspenso ou hotspot bloqueado no painel.");
        }
        var wifiCards = _adapters.ListWifiAdapters();
        if (wifiCards.Count == 0)
        {
            throw new InvalidOperationException("Nenhum adaptador Wi-Fi encontrado. Conecte o adaptador USB TP-Link.");
        }
        _adapters.PrepareAdapters(_config, wifiCards, _log);
        _state.SetStarting();
        _hotspot.ApplyConfiguration(_config, _maxClients, applyOnly, _log);
        if (!applyOnly)
        {
            _hotspot.StartHotspot(_config, _maxClients, _log);
            _supervisor.StartDns();
            _supervisor.StartCaptive();
            _state.SetRunning();
            _runningSince = DateTime.Now;
        }
    }

    private void ExecuteStop()
    {
        _state.SetStopping();
        _hotspot.StopHotspot(_supervisor, _log);
        _supervisor.StopAll();
        _state.SetStopped();
        _runningSince = DateTime.MinValue;
        _adapters.RestoreAdapters(_log);
    }

    public void ForceStopIfBlocked(bool serviceAllowed, string hotspotStatus)
    {
        _serviceAllowed = serviceAllowed;
        if (!serviceAllowed || (hotspotStatus ?? "ativo") != "ativo")
        {
            try
            {
                if (_hotspot.IsOperational() || _state.Current == HotspotState.Running)
                {
                    ExecuteStop();
                }
            }
            catch
            {
            }
        }
    }

    public void ApplySyncResult(CloudSyncResult result)
    {
        if (result == null)
        {
            return;
        }
        _serviceAllowed = result.ServiceAllowed;
        if (result.MaxClients > 0)
        {
            _maxClients = result.MaxClients;
        }
        if (result.Config != null)
        {
            AgentConfigStore.MergeFromSync(result.Config);
            _config = AgentConfigStore.Load();
        }
        if (result.Command != null && !string.IsNullOrEmpty(result.Command.Id))
        {
            _commands.EnqueueFromSync(result.Command.Action, result.Command.Id);
        }
        ForceStopIfBlocked(result.ServiceAllowed, result.HotspotStatus);
    }

    public Dictionary<string, object> BuildStatusPayload()
    {
        bool on = _hotspot.IsOperational();
        if (on)
        {
            _lastError = null;
        }
        var wifiCards = _adapters.ListWifiAdapters();
        var inet = _adapters.GetInternetRoute();
        var payload = new Dictionary<string, object>();
        payload["hotspot_on"] = on;
        payload["ssid"] = _hotspot.CurrentSsid(_config);
        payload["portal_ip"] = _hotspot.GetHotspotIp() ?? _config.PortalIp;
        payload["internet_ip"] = inet.Ip ?? "";
        payload["internet_alias"] = inet.Alias ?? "";
        payload["wifi_adapters"] = wifiCards.Select(WifiAdapterToDict).ToList();
        payload["wifi_adapter_active"] = _hotspot.ActiveAdapterGuid ?? "";
        payload["wifi_adapter_selected"] = _config.WifiAdapterGuid ?? "";
        payload["neighbors"] = _adapters.GetLiveNeighbors();
        payload["tethering_clients"] = _hotspot.GetTetheringClients();
        payload["ips"] = _adapters.ListIpv4();
        payload["windows_clients"] = _hotspot.ClientCount;
        payload["max_clients"] = _maxClients;
        payload["dns_up"] = _supervisor.DnsUp;
        payload["captive_http_up"] = _supervisor.CaptiveUp;
        payload["elevated"] = AgentSecurity.IsElevated();
        payload["task_registered"] = ServiceInstallerHelper.IsServiceInstalled();
        payload["cloud_linked"] = File.Exists(AgentPaths.CloudFile);
        payload["service_allowed"] = _serviceAllowed;
        payload["sync_ok"] = _sync.LastOk;
        payload["sync_error"] = _sync.LastError ?? "";
        payload["sync_at"] = _sync.LastAt ?? "";
        payload["error"] = _lastError;
        payload["agent_version"] = AgentPaths.AgentVersion;
        payload["agent_seen_at"] = DateTime.Now.ToString("s");
        payload["agent_pid"] = Process.GetCurrentProcess().Id;
        payload["agent_state"] = _state.Current.ToString();
        payload["manual_hotspot_required"] = _hotspot.ManualHotspotRequired;
        payload["agent_log"] = _log.Recent();
        payload["last_ack"] = _lastAck;
        return payload;
    }

    private static Dictionary<string, object> WifiAdapterToDict(WifiAdapterInfo a)
    {
        var d = new Dictionary<string, object>();
        d["guid"] = a.Guid;
        d["name"] = a.Name;
        d["desc"] = a.Description;
        d["status"] = a.Status;
        d["iana_type"] = a.IanaType;
        d["connected_ssid"] = a.ConnectedSsid ?? "";
        d["recommended"] = a.Recommended;
        return d;
    }

    private void WriteStatusSnapshot()
    {
        AtomicJson.Write(AgentPaths.StatusFile, BuildStatusPayload());
    }

    public string GetLastAck()
    {
        return _lastAck;
    }

    public AgentLog Log
    {
        get { return _log; }
    }

    public CloudSync Sync
    {
        get { return _sync; }
    }
}

internal enum HotspotState
{
    Stopped,
    Starting,
    Running,
    Stopping,
    Error
}

internal sealed class AgentStateMachine
{
    public HotspotState Current { get; private set; }

    public AgentStateMachine()
    {
        Current = HotspotState.Stopped;
    }

    public string LastError { get; private set; }

    public void SetStarting()
    {
        Current = HotspotState.Starting;
    }

    public void SetRunning()
    {
        Current = HotspotState.Running;
        LastError = null;
    }

    public void SetStopping()
    {
        Current = HotspotState.Stopping;
    }

    public void SetStopped()
    {
        Current = HotspotState.Stopped;
        LastError = null;
    }

    public void SetError(string message)
    {
        Current = HotspotState.Error;
        LastError = message;
    }
}

internal sealed class AgentRuntimeConfig
{
    public string WifiAdapterGuid { get; set; }
    public bool WifiIsolateOthers { get; set; }
    public string Ssid { get; set; }
    public string WifiPass { get; set; }
    public string PortalIp { get; set; }

    public AgentRuntimeConfig()
    {
        WifiAdapterGuid = "";
        WifiIsolateOthers = true;
        Ssid = "WifiDaLoja";
        WifiPass = "loja1234";
        PortalIp = "192.168.137.1";
    }
}

internal static class AgentConfigStore
{
    public static AgentRuntimeConfig Load()
    {
        var cfg = new AgentRuntimeConfig();
        try
        {
            if (File.Exists(AgentPaths.ConfigFile))
            {
                var ser = new JavaScriptSerializer();
                var raw = ser.DeserializeObject(File.ReadAllText(AgentPaths.ConfigFile)) as Dictionary<string, object>;
                if (raw != null)
                {
                    cfg.WifiAdapterGuid = GetStr(raw, "wifi_adapter_guid");
                    cfg.WifiIsolateOthers = GetStr(raw, "wifi_isolate_others") != "0";
                    cfg.Ssid = GetStr(raw, "wifi_ssid", cfg.Ssid);
                    cfg.WifiPass = GetStr(raw, "wifi_pass", cfg.WifiPass);
                    cfg.PortalIp = GetStr(raw, "portal_ip", cfg.PortalIp);
                }
            }
        }
        catch
        {
        }
        try
        {
            if (File.Exists(AgentPaths.AuthFile))
            {
                var ser = new JavaScriptSerializer();
                var raw = ser.DeserializeObject(File.ReadAllText(AgentPaths.AuthFile)) as Dictionary<string, object>;
                if (raw != null)
                {
                    if (!string.IsNullOrEmpty(GetStr(raw, "ssid")))
                    {
                        cfg.Ssid = GetStr(raw, "ssid");
                    }
                    if (!string.IsNullOrEmpty(GetStr(raw, "wifi_pass")))
                    {
                        cfg.WifiPass = GetStr(raw, "wifi_pass");
                    }
                    if (!string.IsNullOrEmpty(GetStr(raw, "portal_ip")))
                    {
                        cfg.PortalIp = GetStr(raw, "portal_ip");
                    }
                }
            }
        }
        catch
        {
        }
        return cfg;
    }

    public static void MergeFromSync(Dictionary<string, object> syncCfg)
    {
        if (syncCfg == null)
        {
            return;
        }
        var existing = Load();
        existing.WifiAdapterGuid = GetStr(syncCfg, "wifi_adapter_guid", existing.WifiAdapterGuid);
        string isolate = GetStr(syncCfg, "wifi_isolate_others");
        if (isolate != "")
        {
            existing.WifiIsolateOthers = isolate != "0";
        }
        existing.Ssid = GetStr(syncCfg, "wifi_ssid", existing.Ssid);
        existing.WifiPass = GetStr(syncCfg, "wifi_pass", existing.WifiPass);
        existing.PortalIp = GetStr(syncCfg, "portal_ip", existing.PortalIp);
        Save(existing);
    }

    public static void Save(AgentRuntimeConfig cfg)
    {
        var payload = new Dictionary<string, object>();
        payload["wifi_adapter_guid"] = cfg.WifiAdapterGuid ?? "";
        payload["wifi_isolate_others"] = cfg.WifiIsolateOthers ? "1" : "0";
        payload["wifi_ssid"] = cfg.Ssid ?? "";
        payload["wifi_pass"] = cfg.WifiPass ?? "";
        payload["portal_ip"] = cfg.PortalIp ?? "192.168.137.1";
        payload["updated_at"] = DateTime.Now.ToString("s");
        AtomicJson.Write(AgentPaths.ConfigFile, payload);
    }

    private static string GetStr(Dictionary<string, object> d, string key, string fallback = "")
    {
        if (d == null || !d.ContainsKey(key) || d[key] == null)
        {
            return fallback;
        }
        return Convert.ToString(d[key]) ?? fallback;
    }
}

internal sealed class WifiAdapterInfo
{
    public string Guid { get; set; }
    public string Name { get; set; }
    public string Description { get; set; }
    public string Status { get; set; }
    public int IanaType { get; set; }
    public string ConnectedSsid { get; set; }
    public bool Recommended { get; set; }
    public object WinRtAdapter { get; set; }
}

internal sealed class AdapterCatalog
{
    private readonly List<string> _disabledAdapters = new List<string>();

    public List<WifiAdapterInfo> ListWifiAdapters()
    {
        var list = new List<WifiAdapterInfo>();
        var seen = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var entry in WinRtHelper.EnumerateWifiAdapters())
        {
            if (seen.Add(entry.Guid ?? entry.Name))
            {
                list.Add(entry);
            }
        }
        foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (ni.NetworkInterfaceType != NetworkInterfaceType.Wireless80211 &&
                ni.Description.IndexOf("802.11", StringComparison.OrdinalIgnoreCase) < 0 &&
                ni.Description.IndexOf("Wi-Fi", StringComparison.OrdinalIgnoreCase) < 0 &&
                ni.Description.IndexOf("Wireless", StringComparison.OrdinalIgnoreCase) < 0 &&
                ni.Name.IndexOf("Wi-Fi", StringComparison.OrdinalIgnoreCase) < 0)
            {
                continue;
            }
            if (ni.NetworkInterfaceType == NetworkInterfaceType.Loopback ||
                ni.Description.IndexOf("Virtual", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Description.IndexOf("Hyper-V", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Description.IndexOf("VMware", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Description.IndexOf("Bluetooth", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Description.IndexOf("Loopback", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Description.IndexOf("KM-TEST", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                continue;
            }
            string id = ni.Id;
            if (string.IsNullOrEmpty(id) || !seen.Add(id))
            {
                continue;
            }
            list.Add(new WifiAdapterInfo
            {
                Guid = id,
                Name = ni.Name,
                Description = ni.Description,
                Status = ni.OperationalStatus == OperationalStatus.Up ? "Up" : "Down",
                IanaType = 71
            });
        }
        if (list.Count == 0)
        {
            foreach (var entry in EnumerateViaNetAdapter())
            {
                if (seen.Add(entry.Guid ?? entry.Name))
                {
                    list.Add(entry);
                }
            }
        }
        ApplyRecommendations(list);
        try
        {
            AtomicJson.Write(Path.Combine(AgentPaths.Storage, "wifi-adapters.json"), list.Select(WifiAdapterToDictPublic).ToList());
            var lines = new StringBuilder();
            foreach (var a in list)
            {
                lines.Append(a.Guid ?? "").Append('\t')
                    .Append((a.Name ?? "").Replace('\t', ' ')).Append('\t')
                    .Append((a.Description ?? "").Replace('\t', ' ')).Append('\t')
                    .Append(a.Recommended ? "1" : "0").Append('\t')
                    .Append(a.Status ?? "").AppendLine();
            }
            File.WriteAllText(Path.Combine(AgentPaths.Storage, "wifi-adapters.txt"), lines.ToString(), new UTF8Encoding(false));
        }
        catch
        {
        }
        return list;
    }

    private static Dictionary<string, object> WifiAdapterToDictPublic(WifiAdapterInfo a)
    {
        var d = new Dictionary<string, object>();
        d["guid"] = a.Guid ?? "";
        d["name"] = a.Name ?? "";
        d["desc"] = a.Description ?? "";
        d["status"] = a.Status ?? "";
        d["iana_type"] = a.IanaType;
        d["connected_ssid"] = a.ConnectedSsid ?? "";
        d["recommended"] = a.Recommended;
        return d;
    }

    private static List<WifiAdapterInfo> EnumerateViaNetAdapter()
    {
        var list = new List<WifiAdapterInfo>();
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe",
                Arguments = "-NoProfile -Command \"Get-NetAdapter | Where-Object { $_.Status -ne 'Not Present' -and ($_.MediaType -eq 'Native 802.11' -or $_.PhysicalMediaType -eq 'Native 802.11' -or $_.InterfaceDescription -match 'Wi-Fi|WiFi|Wireless|802\\.11|WLAN|TP-Link' -or $_.Name -match 'Wi-Fi|WiFi|Wireless|WLAN') -and $_.InterfaceDescription -notmatch 'Loopback|KM-TEST|VirtualBox|VMware|Hyper-V|Bluetooth' } | Select-Object Name,InterfaceDescription,Status,InterfaceGuid | ConvertTo-Json -Compress\"",
                UseShellExecute = false,
                RedirectStandardOutput = true,
                CreateNoWindow = true
            };
            var proc = Process.Start(psi);
            if (proc == null)
            {
                return list;
            }
            string output = proc.StandardOutput.ReadToEnd();
            proc.WaitForExit(10000);
            if (string.IsNullOrWhiteSpace(output))
            {
                return list;
            }
            var ser = new JavaScriptSerializer();
            object parsed = ser.DeserializeObject(output);
            var rows = new List<Dictionary<string, object>>();
            var one = parsed as Dictionary<string, object>;
            if (one != null)
            {
                rows.Add(one);
            }
            else
            {
                var many = parsed as object[];
                if (many != null)
                {
                    foreach (var row in many)
                    {
                        var d = row as Dictionary<string, object>;
                        if (d != null)
                        {
                            rows.Add(d);
                        }
                    }
                }
            }
            foreach (var row in rows)
            {
                string name = Convert.ToString(row.ContainsKey("Name") ? row["Name"] : "");
                string desc = Convert.ToString(row.ContainsKey("InterfaceDescription") ? row["InterfaceDescription"] : "");
                string status = Convert.ToString(row.ContainsKey("Status") ? row["Status"] : "");
                string guid = Convert.ToString(row.ContainsKey("InterfaceGuid") ? row["InterfaceGuid"] : "");
                if (string.IsNullOrEmpty(guid))
                {
                    guid = name;
                }
                list.Add(new WifiAdapterInfo
                {
                    Guid = guid,
                    Name = name,
                    Description = desc,
                    Status = status,
                    IanaType = 71
                });
            }
        }
        catch
        {
        }
        return list;
    }

    public WifiAdapterInfo ResolveHotspotAdapter(AgentRuntimeConfig config, List<WifiAdapterInfo> adapters)
    {
        if (adapters == null || adapters.Count == 0)
        {
            return null;
        }
        if (!string.IsNullOrWhiteSpace(config.WifiAdapterGuid))
        {
            var hit = adapters.FirstOrDefault(a =>
                string.Equals(a.Guid, config.WifiAdapterGuid, StringComparison.OrdinalIgnoreCase));
            if (hit != null)
            {
                return hit;
            }
        }
        var recommended = adapters.FirstOrDefault(a => a.Recommended);
        if (recommended != null)
        {
            return recommended;
        }
        var usb = adapters.FirstOrDefault(a =>
            (a.Description ?? "").IndexOf("USB", StringComparison.OrdinalIgnoreCase) >= 0 ||
            (a.Description ?? "").IndexOf("TP-Link", StringComparison.OrdinalIgnoreCase) >= 0);
        if (usb != null && adapters.Count > 1)
        {
            return usb;
        }
        return adapters[0];
    }

    public void PrepareAdapters(AgentRuntimeConfig config, List<WifiAdapterInfo> adapters, AgentLog log)
    {
        var selected = ResolveHotspotAdapter(config, adapters);
        foreach (var card in adapters)
        {
            NetShHelper.SetInterfaceAdmin(card.Name, true, log);
        }
        if (selected == null || !config.WifiIsolateOthers || adapters.Count <= 1)
        {
            return;
        }
        _disabledAdapters.Clear();
        foreach (var card in adapters)
        {
            if (string.Equals(card.Guid, selected.Guid, StringComparison.OrdinalIgnoreCase) ||
                string.Equals(card.Name, selected.Name, StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }
            if (NetShHelper.SetInterfaceAdmin(card.Name, false, log))
            {
                _disabledAdapters.Add(card.Name);
                log.Info("Adaptador Wi-Fi desabilitado para hotspot: " + card.Name);
            }
        }
    }

    public void RestoreAdapters(AgentLog log)
    {
        foreach (var name in _disabledAdapters.ToList())
        {
            NetShHelper.SetInterfaceAdmin(name, true, log);
        }
        _disabledAdapters.Clear();
    }

    public InternetRouteInfo GetInternetRoute()
    {
        var info = new InternetRouteInfo();
        try
        {
            foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (ni.OperationalStatus != OperationalStatus.Up ||
                    ni.NetworkInterfaceType == NetworkInterfaceType.Loopback)
                {
                    continue;
                }
                var props = ni.GetIPProperties();
                if (props.GatewayAddresses == null || props.GatewayAddresses.Count == 0)
                {
                    continue;
                }
                foreach (var ua in props.UnicastAddresses)
                {
                    if (ua.Address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork &&
                        !ua.Address.ToString().StartsWith("127."))
                    {
                        info.Ip = ua.Address.ToString();
                        info.Alias = ni.Name;
                        return info;
                    }
                }
            }
        }
        catch
        {
        }
        return info;
    }

    public List<Dictionary<string, object>> GetLiveNeighbors()
    {
        var list = new List<Dictionary<string, object>>();
        try
        {
            var proc = Process.Start(new ProcessStartInfo
            {
                FileName = "powershell.exe",
                Arguments = "-NoProfile -Command \"Get-NetNeighbor -AddressFamily IPv4 | Select-Object IPAddress,LinkLayerAddress | ConvertTo-Json -Compress\"",
                UseShellExecute = false,
                RedirectStandardOutput = true,
                CreateNoWindow = true
            });
            if (proc == null)
            {
                return list;
            }
            string output = proc.StandardOutput.ReadToEnd();
            proc.WaitForExit(8000);
            if (string.IsNullOrWhiteSpace(output))
            {
                return list;
            }
            var ser = new JavaScriptSerializer();
            object parsed = ser.DeserializeObject(output);
            var one = parsed as Dictionary<string, object>;
            if (one != null)
            {
                AddNeighbor(list, one);
            }
            else
            {
                var many = parsed as object[];
                if (many != null)
                {
                    foreach (var row in many)
                    {
                        AddNeighbor(list, row as Dictionary<string, object>);
                    }
                }
            }
        }
        catch
        {
        }
        return list;
    }

    private static void AddNeighbor(List<Dictionary<string, object>> list, Dictionary<string, object> row)
    {
        if (row == null)
        {
            return;
        }
        string ip = Convert.ToString(row.ContainsKey("IPAddress") ? row["IPAddress"] : "");
        string mac = Convert.ToString(row.ContainsKey("LinkLayerAddress") ? row["LinkLayerAddress"] : "");
        if (string.IsNullOrWhiteSpace(ip) || string.IsNullOrWhiteSpace(mac))
        {
            return;
        }
        var d = new Dictionary<string, object>();
        d["ip"] = ip;
        d["mac"] = mac.Replace("-", ":");
        list.Add(d);
    }

    public List<Dictionary<string, object>> ListIpv4()
    {
        var list = new List<Dictionary<string, object>>();
        foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (ni.OperationalStatus != OperationalStatus.Up)
            {
                continue;
            }
            foreach (var ua in ni.GetIPProperties().UnicastAddresses)
            {
                if (ua.Address.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork)
                {
                    continue;
                }
                string ip = ua.Address.ToString();
                if (ip.StartsWith("127."))
                {
                    continue;
                }
                var d = new Dictionary<string, object>();
                d["ip"] = ip;
                d["alias"] = ni.Name;
                list.Add(d);
            }
        }
        return list;
    }

    private static void ApplyRecommendations(List<WifiAdapterInfo> adapters)
    {
        if (adapters.Count <= 1)
        {
            if (adapters.Count == 1)
            {
                adapters[0].Recommended = true;
            }
            return;
        }
        foreach (var a in adapters)
        {
            bool usb = (a.Description ?? "").IndexOf("USB", StringComparison.OrdinalIgnoreCase) >= 0 ||
                       (a.Description ?? "").IndexOf("TP-Link", StringComparison.OrdinalIgnoreCase) >= 0;
            bool connectedElsewhere = !string.IsNullOrWhiteSpace(a.ConnectedSsid);
            a.Recommended = usb && !connectedElsewhere;
        }
        if (!adapters.Any(a => a.Recommended))
        {
            var pick = adapters.FirstOrDefault(a => string.IsNullOrWhiteSpace(a.ConnectedSsid)) ?? adapters[0];
            pick.Recommended = true;
        }
    }
}

internal sealed class InternetRouteInfo
{
    public string Ip { get; set; }
    public string Alias { get; set; }
}

internal static class NetShHelper
{
    public static bool SetInterfaceAdmin(string name, bool enabled, AgentLog log)
    {
        if (string.IsNullOrWhiteSpace(name))
        {
            return false;
        }
        try
        {
            string state = enabled ? "enabled" : "disabled";
            var psi = new ProcessStartInfo
            {
                FileName = "netsh.exe",
                Arguments = "interface set interface name=\"" + name + "\" admin=" + state,
                UseShellExecute = false,
                CreateNoWindow = true
            };
            var proc = Process.Start(psi);
            if (proc == null)
            {
                return false;
            }
            proc.WaitForExit(10000);
            return proc.ExitCode == 0;
        }
        catch (Exception ex)
        {
            log.Warn("netsh " + name + ": " + ex.Message);
            return false;
        }
    }
}

internal sealed class HotspotController
{
    private object _manager;
    private string _activeGuid;
    public bool ManualHotspotRequired { get; private set; }
    public int ClientCount { get; private set; }

    public string ActiveAdapterGuid
    {
        get { return _activeGuid ?? ""; }
    }

    public string CurrentSsid(AgentRuntimeConfig config)
    {
        try
        {
            if (_manager != null)
            {
                object cfg = _manager.GetType().InvokeMember("GetCurrentAccessPointConfiguration", BindingFlags.InvokeMethod, null, _manager, null);
                if (cfg != null)
                {
                    return Convert.ToString(cfg.GetType().GetProperty("Ssid").GetValue(cfg, null));
                }
            }
        }
        catch
        {
        }
        return config.Ssid;
    }

    public bool IsOperational()
    {
        try
        {
            if (_manager == null)
            {
                return false;
            }
            object state = _manager.GetType().GetProperty("TetheringOperationalState").GetValue(_manager, null);
            return Convert.ToInt32(state) == 1;
        }
        catch
        {
            return false;
        }
    }

    public void ApplyConfiguration(AgentRuntimeConfig config, int maxClients, bool applyOnly, AgentLog log)
    {
        EnsureManager(config, log);
        object apCfg = _manager.GetType().InvokeMember("GetCurrentAccessPointConfiguration", BindingFlags.InvokeMethod, null, _manager, null);
        apCfg.GetType().GetProperty("Ssid").SetValue(apCfg, config.Ssid, null);
        apCfg.GetType().GetProperty("Passphrase").SetValue(apCfg, config.WifiPass, null);
        try
        {
            apCfg.GetType().GetProperty("MaxClientCount").SetValue(apCfg, (uint)maxClients, null);
        }
        catch
        {
        }
        object op = _manager.GetType().InvokeMember("ConfigureAccessPointAsync", BindingFlags.InvokeMethod, null, _manager, new object[] { apCfg });
        WinRtHelper.WaitAction(op, log);
        if (applyOnly)
        {
            return;
        }
    }

    public void StartHotspot(AgentRuntimeConfig config, int maxClients, AgentLog log)
    {
        EnsureManager(config, log);
        ApplyConfiguration(config, maxClients, false, log);
        if (IsOperational())
        {
            return;
        }
        StartTetheringSafe(log);
    }

    public void StopHotspot(ProcessSupervisor supervisor, AgentLog log)
    {
        try
        {
            if (_manager != null && IsOperational())
            {
                object op = _manager.GetType().InvokeMember("StopTetheringAsync", BindingFlags.InvokeMethod, null, _manager, null);
                WinRtHelper.WaitOperation(op, log);
            }
        }
        catch (Exception ex)
        {
            log.Warn("Stop hotspot: " + ex.Message);
        }
        _manager = null;
        _activeGuid = null;
        supervisor.StopAll();
    }

    public void RestartIcs()
    {
        try
        {
            var svc = ServiceController.GetServices().FirstOrDefault(s => string.Equals(s.ServiceName, "icssvc", StringComparison.OrdinalIgnoreCase));
            if (svc == null)
            {
                return;
            }
            if (svc.Status == ServiceControllerStatus.Running)
            {
                svc.Stop();
                svc.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(15));
            }
            svc.Start();
            svc.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(15));
            Thread.Sleep(2000);
        }
        catch
        {
        }
        _manager = null;
    }

    public string GetHotspotIp()
    {
        foreach (var ni in NetworkInterface.GetAllNetworkInterfaces())
        {
            if (ni.Name.IndexOf("Direct", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Name.IndexOf("Hosted", StringComparison.OrdinalIgnoreCase) >= 0 ||
                ni.Name.IndexOf("Local Area Connection", StringComparison.OrdinalIgnoreCase) >= 0)
            {
                foreach (var ua in ni.GetIPProperties().UnicastAddresses)
                {
                    if (ua.Address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork)
                    {
                        return ua.Address.ToString();
                    }
                }
            }
            foreach (var ua in ni.GetIPProperties().UnicastAddresses)
            {
                if (ua.Address.ToString().StartsWith("192.168.137."))
                {
                    return ua.Address.ToString();
                }
            }
        }
        return null;
    }

    public List<Dictionary<string, object>> GetTetheringClients()
    {
        var list = new List<Dictionary<string, object>>();
        try
        {
            if (_manager == null)
            {
                return list;
            }
            object op = _manager.GetType().InvokeMember("GetTetheringClientsAsync", BindingFlags.InvokeMethod, null, _manager, null);
            object clients = WinRtHelper.WaitOperation(op, null);
            ClientCount = 0;
            var arr = clients as Array;
            if (arr != null)
            {
                ClientCount = arr.Length;
                foreach (var c in arr)
                {
                    string mac = Convert.ToString(c.GetType().GetProperty("MacAddress").GetValue(c, null));
                    var d = new Dictionary<string, object>();
                    d["mac"] = mac;
                    list.Add(d);
                }
            }
        }
        catch
        {
        }
        return list;
    }

    private void EnsureManager(AgentRuntimeConfig config, AgentLog log)
    {
        if (_manager != null)
        {
            return;
        }
        var catalog = new AdapterCatalog();
        var adapters = catalog.ListWifiAdapters();
        var selected = catalog.ResolveHotspotAdapter(config, adapters);
        if (selected == null)
        {
            throw new InvalidOperationException("Nenhum adaptador Wi-Fi disponivel para hotspot.");
        }
        _activeGuid = selected.Guid;
        object internetProfile = WinRtHelper.GetInternetConnectionProfile(log);
        object wifiAdapter = selected.WinRtAdapter ?? WinRtHelper.FindNetworkAdapterByGuid(selected.Guid, log);
        if (internetProfile == null)
        {
            throw new InvalidOperationException("Nao foi possivel detectar a conexao de internet (Ethernet/Wi-Fi).");
        }
        if (wifiAdapter == null)
        {
            _manager = WinRtHelper.CreateTetheringManager(internetProfile, log);
        }
        else
        {
            _manager = WinRtHelper.CreateTetheringManager(internetProfile, wifiAdapter, log);
        }
        if (_manager == null)
        {
            throw new InvalidOperationException("Windows nao permitiu criar o hotspot neste adaptador.");
        }
    }

    private void StartTetheringSafe(AgentLog log)
    {
        bool retriedIcs = false;
        for (int attempt = 1; attempt <= 3; attempt++)
        {
            object op = _manager.GetType().InvokeMember("StartTetheringAsync", BindingFlags.InvokeMethod, null, _manager, null);
            object result = WinRtHelper.WaitOperation(op, log);
            int code = WinRtHelper.ReadTetheringStatus(result);
            if (code == 0 || code == 9)
            {
                ManualHotspotRequired = false;
                return;
            }
            if (code == 1 && !retriedIcs)
            {
                ManualHotspotRequired = true;
                RestartIcs();
                retriedIcs = true;
                continue;
            }
            if (code == 6 && attempt < 3)
            {
                Thread.Sleep(3000);
                continue;
            }
            string hint = TetheringHints.ForCode(code);
            if (!string.IsNullOrEmpty(hint))
            {
                throw new InvalidOperationException(hint);
            }
            throw new InvalidOperationException("Codigo Windows " + code + " ao ligar hotspot.");
        }
    }
}

internal static class TetheringHints
{
    public static string ForCode(int code)
    {
        switch (code)
        {
            case 1:
                return "Ponto de acesso movel nao iniciou. Abra Configuracoes > Rede > Ponto de acesso movel, ligue uma vez manualmente.";
            case 2:
                return "Modem movel desligado. Verifique Ethernet/Wi-Fi de internet.";
            case 3:
                return "Wi-Fi desligado. Ative o adaptador Wi-Fi de transmissao.";
            case 5:
                return "Esta conexao nao permite ponto de acesso movel.";
            case 8:
                return "Wi-Fi ocupado. Desconecte o adaptador USB de outras redes Wi-Fi.";
            case 10:
                return "Restricao de radio/banda no adaptador Wi-Fi.";
            case 11:
                return "Interferencia de banda. Use o seletor de adaptador no painel ou desconecte Wi-Fi interno.";
            default:
                return code > 0 ? "Codigo Windows " + code + " ao ligar hotspot." : "";
        }
    }
}

internal static class WinRtHelper
{
    private static bool _initialized;
    private static Type _mgrType;
    private static Type _infoType;

    private static void Init()
    {
        if (_initialized)
        {
            return;
        }
        _initialized = true;
        try
        {
            Assembly.Load("System.Runtime.WindowsRuntime");
        }
        catch
        {
        }
        _mgrType = Type.GetType("Windows.Networking.NetworkOperators.NetworkOperatorTetheringManager, Windows.Networking.NetworkOperators, ContentType=WindowsRuntime");
        _infoType = Type.GetType("Windows.Networking.Connectivity.NetworkInformation, Windows.Networking.Connectivity, ContentType=WindowsRuntime");
    }

    public static List<WifiAdapterInfo> EnumerateWifiAdapters()
    {
        Init();
        var list = new List<WifiAdapterInfo>();
        if (_infoType == null)
        {
            return list;
        }
        try
        {
            object profiles = _infoType.InvokeMember("GetConnectionProfiles", BindingFlags.InvokeMethod, null, null, null);
            var arr = profiles as Array;
            if (arr != null)
            {
                foreach (var p in arr)
                {
                    object adapter = p.GetType().GetProperty("NetworkAdapter").GetValue(p, null);
                    if (adapter == null)
                    {
                        continue;
                    }
                    int iana = Convert.ToInt32(adapter.GetType().GetProperty("IanaInterfaceType").GetValue(adapter, null));
                    if (iana != 71)
                    {
                        continue;
                    }
                    string name = Convert.ToString(p.GetType().GetProperty("ProfileName").GetValue(p, null));
                    string desc = Convert.ToString(adapter.GetType().GetProperty("Description").GetValue(adapter, null));
                    object guidObj = adapter.GetType().GetProperty("NetworkAdapterId").GetValue(adapter, null);
                    string guid = guidObj != null ? guidObj.ToString() : name;
                    list.Add(new WifiAdapterInfo
                    {
                        Guid = guid,
                        Name = name,
                        Description = desc,
                        Status = "Up",
                        IanaType = 71,
                        ConnectedSsid = name,
                        WinRtAdapter = adapter
                    });
                }
            }
        }
        catch
        {
        }
        return list;
    }

    public static object GetInternetConnectionProfile(AgentLog log)
    {
        Init();
        if (_infoType == null)
        {
            return null;
        }
        var profiles = new List<object>();
        try
        {
            object inet = _infoType.InvokeMember("GetInternetConnectionProfile", BindingFlags.InvokeMethod, null, null, null);
            if (inet != null && !IsBadProfile(inet))
            {
                profiles.Add(inet);
            }
        }
        catch
        {
        }
        try
        {
            object all = _infoType.InvokeMember("GetConnectionProfiles", BindingFlags.InvokeMethod, null, null, null);
            var arr2 = all as Array;
            if (arr2 != null)
            {
                foreach (var p in arr2)
                {
                    if (!IsBadProfile(p))
                    {
                        profiles.Add(p);
                    }
                }
            }
        }
        catch
        {
        }
        foreach (var p in profiles.OrderByDescending(ProfileScore))
        {
            try
            {
                if (_mgrType != null)
                {
                    object cap = _mgrType.InvokeMember("GetTetheringCapabilityFromConnectionProfile", BindingFlags.InvokeMethod, null, null, new object[] { p });
                    if (Convert.ToInt32(cap) == 0)
                    {
                        return p;
                    }
                }
            }
            catch
            {
            }
        }
        return profiles.FirstOrDefault();
    }

    private static int ProfileScore(object profile)
    {
        try
        {
            object adapter = profile.GetType().GetProperty("NetworkAdapter").GetValue(profile, null);
            int iana = Convert.ToInt32(adapter.GetType().GetProperty("IanaInterfaceType").GetValue(adapter, null));
            return iana == 6 ? 10 : 1;
        }
        catch
        {
            return 0;
        }
    }

    private static bool IsBadProfile(object profile)
    {
        try
        {
            string name = Convert.ToString(profile.GetType().GetProperty("ProfileName").GetValue(profile, null));
            return name.IndexOf("Loopback", StringComparison.OrdinalIgnoreCase) >= 0 ||
                   name.IndexOf("Topaz", StringComparison.OrdinalIgnoreCase) >= 0 ||
                   name.IndexOf("KM-TEST", StringComparison.OrdinalIgnoreCase) >= 0;
        }
        catch
        {
            return true;
        }
    }

    public static object FindNetworkAdapterByGuid(string guid, AgentLog log)
    {
        foreach (var a in EnumerateWifiAdapters())
        {
            if (string.Equals(a.Guid, guid, StringComparison.OrdinalIgnoreCase))
            {
                return a.WinRtAdapter;
            }
        }
        return null;
    }

    public static object CreateTetheringManager(object internetProfile, AgentLog log)
    {
        Init();
        if (_mgrType == null || internetProfile == null)
        {
            return null;
        }
        return _mgrType.InvokeMember("CreateFromConnectionProfile", BindingFlags.InvokeMethod, null, null, new[] { internetProfile });
    }

    public static object CreateTetheringManager(object internetProfile, object wifiAdapter, AgentLog log)
    {
        Init();
        if (_mgrType == null || internetProfile == null || wifiAdapter == null)
        {
            return CreateTetheringManager(internetProfile, log);
        }
        try
        {
            return _mgrType.InvokeMember("CreateFromConnectionProfile", BindingFlags.InvokeMethod, null, null, new[] { internetProfile, wifiAdapter });
        }
        catch
        {
            return CreateTetheringManager(internetProfile, log);
        }
    }

    public static object WaitOperation(object asyncOp, AgentLog log)
    {
        return WaitAsync(asyncOp, true, log);
    }

    public static void WaitAction(object asyncOp, AgentLog log)
    {
        WaitAsync(asyncOp, false, log);
    }

    private static object WaitAsync(object asyncOp, bool generic, AgentLog log)
    {
        if (asyncOp == null)
        {
            return null;
        }
        Type extType = Type.GetType("System.WindowsRuntimeSystemExtensions, System.Runtime.WindowsRuntime, Version=4.0.0.0, Culture=neutral, PublicKeyToken=b77a5c561934e089");
        if (extType == null)
        {
            throw new InvalidOperationException("WinRT AsTask indisponivel (System.Runtime.WindowsRuntime).");
        }
        MethodInfo asTask = extType.GetMethods(BindingFlags.Public | BindingFlags.Static)
            .FirstOrDefault(m => m.Name == "AsTask" && m.IsGenericMethod == generic && m.GetParameters().Length == 1);
        if (asTask == null)
        {
            throw new InvalidOperationException("WinRT AsTask indisponivel.");
        }
        MethodInfo call = generic ? asTask.MakeGenericMethod(asyncOp.GetType().GetGenericArguments()[0]) : asTask;
        object task = call.Invoke(null, new[] { asyncOp });
        var wait = task.GetType().GetMethod("Wait", new[] { typeof(int) });
        if (!(bool)wait.Invoke(task, new object[] { 25000 }))
        {
            throw new System.TimeoutException("Tempo esgotado aguardando operacao WinRT.");
        }
        var fault = (bool)task.GetType().GetProperty("IsFaulted").GetValue(task, null);
        if (fault)
        {
            throw new InvalidOperationException(((Exception)task.GetType().GetProperty("Exception").GetValue(task, null)).GetBaseException().Message);
        }
        if (!generic)
        {
            return null;
        }
        return task.GetType().GetProperty("Result").GetValue(task, null);
    }

    public static int ReadTetheringStatus(object result)
    {
        if (result == null)
        {
            return -1;
        }
        try
        {
            object status = result.GetType().GetProperty("Status").GetValue(result, null);
            return Convert.ToInt32(status);
        }
        catch
        {
            return -1;
        }
    }
}

internal sealed class ProcessSupervisor
{
    private Process _dns;
    private Process _captive;
    private DateTime _lastWatch = DateTime.MinValue;

    public bool DnsUp
    {
        get { return _dns != null && !_dns.HasExited; }
    }

    public bool CaptiveUp
    {
        get { return _captive != null && !_captive.HasExited; }
    }

    public void StartDns()
    {
        if (DnsUp)
        {
            return;
        }
        if (!File.Exists(AgentPaths.DnsProxyExe))
        {
            throw new FileNotFoundException("DnsProxy.exe nao encontrado.");
        }
        _dns = Process.Start(new ProcessStartInfo
        {
            FileName = AgentPaths.DnsProxyExe,
            WorkingDirectory = AgentPaths.InstallRoot,
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden
        });
    }

    public void StartCaptive()
    {
        if (CaptiveUp || !File.Exists(AgentPaths.CaptiveHttpExe))
        {
            return;
        }
        _captive = Process.Start(new ProcessStartInfo
        {
            FileName = AgentPaths.CaptiveHttpExe,
            WorkingDirectory = AgentPaths.InstallRoot,
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden
        });
    }

    public void StopAll()
    {
        KillProcess(ref _dns);
        KillProcess(ref _captive);
        foreach (var proc in Process.GetProcessesByName("DnsProxy"))
        {
            try { proc.Kill(); } catch { }
        }
        foreach (var proc in Process.GetProcessesByName("CaptiveHttp"))
        {
            try { proc.Kill(); } catch { }
        }
    }

    public void Watchdog(AgentLog log)
    {
        if ((DateTime.Now - _lastWatch).TotalSeconds < 5)
        {
            return;
        }
        _lastWatch = DateTime.Now;
        if (_dns != null && _dns.HasExited)
        {
            log.Warn("DnsProxy parou; reiniciando");
            _dns = null;
            try { StartDns(); } catch (Exception ex) { log.Error(ex.Message); }
        }
        if (_captive != null && _captive.HasExited)
        {
            log.Warn("CaptiveHttp parou; reiniciando");
            _captive = null;
            try { StartCaptive(); } catch (Exception ex) { log.Error(ex.Message); }
        }
    }

    private static void KillProcess(ref Process proc)
    {
        if (proc == null)
        {
            return;
        }
        try
        {
            if (!proc.HasExited)
            {
                proc.Kill();
            }
        }
        catch
        {
        }
        proc = null;
    }
}

internal sealed class CommandQueue
{
    private readonly HashSet<string> _processed = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

    public CommandQueue()
    {
        LoadProcessed();
    }

    public string Enqueue(string action, string sourceId)
    {
        string id = string.IsNullOrWhiteSpace(sourceId) ? Guid.NewGuid().ToString("N").Substring(0, 16) : sourceId;
        var payload = new Dictionary<string, object>();
        payload["id"] = id;
        payload["action"] = action;
        payload["at"] = DateTime.Now.ToString("o");
        string path = Path.Combine(AgentPaths.CommandsDir, id + ".json");
        AtomicJson.Write(path, payload);
        return id;
    }

    public void EnqueueFromSync(string action, string id)
    {
        if (_processed.Contains(id))
        {
            return;
        }
        Enqueue(action, id);
    }

    public void ProcessPending(AgentEngine engine)
    {
        ImportLegacyCommandFile();
        foreach (string file in Directory.GetFiles(AgentPaths.CommandsDir, "*.json"))
        {
            if (file.EndsWith("processed.json", StringComparison.OrdinalIgnoreCase))
            {
                continue;
            }
            try
            {
                var ser = new JavaScriptSerializer();
                var raw = ser.DeserializeObject(File.ReadAllText(file)) as Dictionary<string, object>;
                if (raw == null)
                {
                    continue;
                }
                string id = Convert.ToString(raw.ContainsKey("id") ? raw["id"] : "");
                string action = Convert.ToString(raw.ContainsKey("action") ? raw["action"] : "");
                if (string.IsNullOrEmpty(id) || _processed.Contains(id))
                {
                    continue;
                }
                engine.HandleCommand(action, id);
                _processed.Add(id);
                SaveProcessed();
                try { File.Delete(file); } catch { }
            }
            catch
            {
            }
        }
    }

    private void ImportLegacyCommandFile()
    {
        try
        {
            string legacy = Path.Combine(AgentPaths.Storage, "command.json");
            if (!File.Exists(legacy))
            {
                return;
            }
            var ser = new JavaScriptSerializer();
            var raw = ser.DeserializeObject(File.ReadAllText(legacy)) as Dictionary<string, object>;
            if (raw == null)
            {
                return;
            }
            string id = Convert.ToString(raw.ContainsKey("id") ? raw["id"] : "");
            string action = Convert.ToString(raw.ContainsKey("action") ? raw["action"] : "");
            if (string.IsNullOrEmpty(id) || _processed.Contains(id))
            {
                return;
            }
            Enqueue(action, id);
            try { File.Delete(legacy); } catch { }
        }
        catch
        {
        }
    }

    private void LoadProcessed()
    {
        try
        {
            if (!File.Exists(AgentPaths.ProcessedFile))
            {
                return;
            }
            var ser = new JavaScriptSerializer();
            var raw = ser.DeserializeObject(File.ReadAllText(AgentPaths.ProcessedFile)) as Dictionary<string, object>;
            if (raw == null || !raw.ContainsKey("ids"))
            {
                return;
            }
            if (raw.ContainsKey("ids"))
            {
                var arr = raw["ids"] as object[];
                if (arr != null)
                {
                    foreach (var id in arr)
                    {
                        _processed.Add(Convert.ToString(id));
                    }
                }
            }
        }
        catch
        {
        }
    }

    private void SaveProcessed()
    {
        var ids = _processed.ToList();
        if (ids.Count > 200)
        {
            ids = ids.Skip(ids.Count - 200).ToList();
        }
        var payload = new Dictionary<string, object>();
        payload["ids"] = ids;
        payload["updated_at"] = DateTime.Now.ToString("s");
        AtomicJson.Write(AgentPaths.ProcessedFile, payload);
    }
}

internal sealed class CloudSyncResult
{
    public bool ServiceAllowed { get; set; }
    public int MaxClients { get; set; }
    public string HotspotStatus { get; set; }
    public Dictionary<string, object> Config { get; set; }
    public SyncCommand Command { get; set; }

    public CloudSyncResult()
    {
        ServiceAllowed = true;
        HotspotStatus = "ativo";
    }
}

internal sealed class SyncCommand
{
    public string Id { get; set; }
    public string Action { get; set; }
}

internal sealed class CloudSync
{
    public bool LastOk { get; private set; }
    public string LastError { get; private set; }
    public string LastAt { get; private set; }

    public void Run(AgentEngine engine)
    {
        try
        {
            var cloud = ReadCloud();
            if (cloud == null)
            {
                LastOk = false;
                LastError = "cloud.json ausente ou incompleto.";
                LastAt = DateTime.Now.ToString("s");
                engine.Log.Error(LastError);
                return;
            }
            string url = cloud.PanelUrl.TrimEnd('/') + "/agente/sincronizar";
            var status = engine.BuildStatusPayload();
            var payload = new Dictionary<string, object>();
            payload["token"] = cloud.Token;
            payload["ack_command_id"] = engine.GetLastAck();
            payload["status"] = status;
            payload["clients"] = new object[0];
            string json = new JavaScriptSerializer().Serialize(payload);
            var req = (HttpWebRequest)WebRequest.Create(url);
            req.Method = "POST";
            req.ContentType = "application/json; charset=utf-8";
            req.Timeout = 8000;
            req.Headers["X-Agent-Token"] = cloud.Token;
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
            byte[] body = Encoding.UTF8.GetBytes(json);
            req.ContentLength = body.Length;
            using (var stream = req.GetRequestStream())
            {
                stream.Write(body, 0, body.Length);
            }
            using (var resp = (HttpWebResponse)req.GetResponse())
            using (var reader = new StreamReader(resp.GetResponseStream()))
            {
                string text = reader.ReadToEnd();
                HandleResponse(engine, text);
            }
            LastOk = true;
            LastError = "";
            LastAt = DateTime.Now.ToString("s");
            engine.Log.Info("Sync OK com painel");
        }
        catch (WebException wex)
        {
            string msg = wex.Message;
            var http = wex.Response as HttpWebResponse;
            if (http != null && (int)http.StatusCode == 401)
            {
                msg = "Token invalido ou expirado. Revincule o hotspot no painel.";
            }
            LastOk = false;
            LastError = msg;
            LastAt = DateTime.Now.ToString("s");
            engine.Log.Error("Sync falhou: " + msg);
            WriteSyncError(msg);
        }
        catch (Exception ex)
        {
            LastOk = false;
            LastError = ex.Message;
            LastAt = DateTime.Now.ToString("s");
            engine.Log.Error("Sync falhou: " + ex.Message);
            WriteSyncError(ex.Message);
        }
    }

    private static void HandleResponse(AgentEngine engine, string json)
    {
        var ser = new JavaScriptSerializer();
        var resp = ser.DeserializeObject(json) as Dictionary<string, object>;
        if (resp == null || !resp.ContainsKey("ok") || !Convert.ToBoolean(resp["ok"]))
        {
            throw new InvalidOperationException("Resposta invalida do painel.");
        }
        var result = new CloudSyncResult();
        result.HotspotStatus = GetStr(resp, "hotspot_status", "ativo");
        var cfg = resp.ContainsKey("config") ? resp["config"] as Dictionary<string, object> : null;
        if (cfg != null)
        {
            result.Config = cfg;
            int max = 0;
            int.TryParse(GetStr(cfg, "max_clients"), out max);
            result.MaxClients = max;
            WriteAuthorized(cfg, resp);
        }
        var sub = resp.ContainsKey("subscription") ? resp["subscription"] as Dictionary<string, object> : null;
        if (sub != null)
        {
            result.ServiceAllowed = !sub.ContainsKey("service_allowed") || Convert.ToBoolean(sub["service_allowed"]);
        }
        var cmd = resp.ContainsKey("command") ? resp["command"] as Dictionary<string, object> : null;
        if (cmd != null)
        {
            result.Command = new SyncCommand
            {
                Id = GetStr(cmd, "id"),
                Action = GetStr(cmd, "action")
            };
        }
        WriteStoreInfo(resp);
        engine.ApplySyncResult(result);
        try
        {
            if (File.Exists(Path.Combine(AgentPaths.Storage, "sync-error.json")))
            {
                File.Delete(Path.Combine(AgentPaths.Storage, "sync-error.json"));
            }
        }
        catch
        {
        }
    }

    private static void WriteAuthorized(Dictionary<string, object> cfg, Dictionary<string, object> resp)
    {
        var auth = new Dictionary<string, object>();
        auth["portal_ip"] = GetStr(cfg, "portal_ip", "192.168.137.1");
        auth["portal_url"] = GetLink(resp, "portal");
        auth["authorized"] = resp.ContainsKey("authorized") ? resp["authorized"] : new object[0];
        auth["allow_suffixes"] = SplitLines(GetStr(cfg, "dns_allowlist"));
        auth["ssid"] = GetStr(cfg, "wifi_ssid");
        auth["wifi_pass"] = GetStr(cfg, "wifi_pass");
        auth["wifi_adapter_guid"] = GetStr(cfg, "wifi_adapter_guid");
        auth["wifi_isolate_others"] = GetStr(cfg, "wifi_isolate_others", "1");
        auth["updated_at"] = DateTime.Now.ToString("s");
        AtomicJson.Write(AgentPaths.AuthFile, auth);
        AgentConfigStore.MergeFromSync(cfg);
    }

    private static void WriteStoreInfo(Dictionary<string, object> resp)
    {
        var info = new Dictionary<string, object>();
        info["store_id"] = resp.ContainsKey("store_id") ? resp["store_id"] : 0;
        info["store_name"] = GetStr(resp, "store");
        var cfg = resp.ContainsKey("config") ? resp["config"] as Dictionary<string, object> : null;
        if (cfg != null)
        {
            info["store_city"] = GetStr(cfg, "store_city");
            info["wifi_ssid"] = GetStr(cfg, "wifi_ssid");
            info["wifi_pass"] = GetStr(cfg, "wifi_pass");
            info["portal_ip"] = GetStr(cfg, "portal_ip", "192.168.137.1");
            info["max_clients"] = GetStr(cfg, "max_clients", "8");
        }
        info["hotspot_status"] = GetStr(resp, "hotspot_status", "ativo");
        info["panel_url"] = GetLink(resp, "panel");
        info["admin_url"] = GetLink(resp, "admin");
        info["client_url"] = GetLink(resp, "client");
        info["portal_url"] = GetLink(resp, "portal");
        info["agent_version"] = AgentPaths.AgentVersion;
        info["updated_at"] = DateTime.Now.ToString("s");
        var sub = resp.ContainsKey("subscription") ? resp["subscription"] as Dictionary<string, object> : null;
        if (sub != null)
        {
            foreach (var key in new[] { "scope", "billing_status", "billing_label", "plan", "plan_label", "paid_until", "trial_ends_at", "cycle_amount", "active", "service_allowed" })
            {
                if (sub.ContainsKey(key))
                {
                    info[key] = sub[key];
                }
            }
        }
        AtomicJson.Write(Path.Combine(AgentPaths.Storage, "store-info.json"), info);
    }

    private static CloudCredentials ReadCloud()
    {
        try
        {
            if (!File.Exists(AgentPaths.CloudFile))
            {
                return null;
            }
            var ser = new JavaScriptSerializer();
            var raw = ser.DeserializeObject(File.ReadAllText(AgentPaths.CloudFile)) as Dictionary<string, object>;
            if (raw == null)
            {
                return null;
            }
            string url = GetStr(raw, "panel_url");
            string token = GetStr(raw, "token");
            if (url.Length < 8 || token.Length < 8)
            {
                return null;
            }
            return new CloudCredentials { PanelUrl = url, Token = token };
        }
        catch
        {
            return null;
        }
    }

    private static void WriteSyncError(string message)
    {
        var payload = new Dictionary<string, object>();
        payload["error"] = message;
        payload["updated_at"] = DateTime.Now.ToString("s");
        AtomicJson.Write(Path.Combine(AgentPaths.Storage, "sync-error.json"), payload);
    }

    private static string GetStr(Dictionary<string, object> d, string key, string fallback = "")
    {
        if (d == null || !d.ContainsKey(key) || d[key] == null)
        {
            return fallback;
        }
        return Convert.ToString(d[key]) ?? fallback;
    }

    private static string GetLink(Dictionary<string, object> resp, string key)
    {
        var links = resp.ContainsKey("links") ? resp["links"] as Dictionary<string, object> : null;
        if (links != null)
        {
            return GetStr(links, key);
        }
        return "";
    }

    private static object[] SplitLines(string text)
    {
        if (string.IsNullOrWhiteSpace(text))
        {
            return new object[0];
        }
        return text.Split(new[] { "\r\n", "\n" }, StringSplitOptions.RemoveEmptyEntries).Cast<object>().ToArray();
    }
}

internal sealed class CloudCredentials
{
    public string PanelUrl { get; set; }
    public string Token { get; set; }
}

internal sealed class NamedPipeServer
{
    private Thread _thread;
    private volatile bool _running;
    private AgentEngine _engine;

    public void Start(AgentEngine engine)
    {
        _engine = engine;
        _running = true;
        _thread = new Thread(ListenLoop) { IsBackground = true, Name = "WiFiDaLojaPipe" };
        _thread.Start();
    }

    public void Stop()
    {
        _running = false;
    }

    private void ListenLoop()
    {
        while (_running)
        {
            try
            {
                var security = new System.IO.Pipes.PipeSecurity();
                security.AddAccessRule(new System.IO.Pipes.PipeAccessRule(
                    new System.Security.Principal.SecurityIdentifier(System.Security.Principal.WellKnownSidType.WorldSid, null),
                    System.IO.Pipes.PipeAccessRights.FullControl,
                    System.Security.AccessControl.AccessControlType.Allow));
                security.AddAccessRule(new System.IO.Pipes.PipeAccessRule(
                    new System.Security.Principal.SecurityIdentifier(System.Security.Principal.WellKnownSidType.LocalSystemSid, null),
                    System.IO.Pipes.PipeAccessRights.FullControl,
                    System.Security.AccessControl.AccessControlType.Allow));
                using (var server = new System.IO.Pipes.NamedPipeServerStream(
                    AgentConstants.PipeName,
                    System.IO.Pipes.PipeDirection.InOut,
                    4,
                    System.IO.Pipes.PipeTransmissionMode.Byte,
                    System.IO.Pipes.PipeOptions.Asynchronous,
                    0,
                    0,
                    security))
                {
                    server.WaitForConnection();
                    using (var reader = new StreamReader(server, Encoding.UTF8))
                    using (var writer = new StreamWriter(server, Encoding.UTF8) { AutoFlush = true })
                    {
                        string line = reader.ReadLine();
                        string response = HandleRequest(line ?? "");
                        writer.WriteLine(response);
                    }
                }
            }
            catch
            {
                Thread.Sleep(300);
            }
        }
    }

    private string HandleRequest(string line)
    {
        try
        {
            var ser = new JavaScriptSerializer();
            var req = ser.DeserializeObject(line) as Dictionary<string, object>;
            string cmd = req != null && req.ContainsKey("cmd") ? Convert.ToString(req["cmd"]) : "";
            switch ((cmd ?? "").Trim().ToLowerInvariant())
            {
                case "ping":
                    return ser.Serialize(new Dictionary<string, object> { { "ok", true }, { "version", AgentPaths.AgentVersion } });
                case "status":
                    return ser.Serialize(new Dictionary<string, object> { { "ok", true }, { "status", _engine.BuildStatusPayload() } });
                case "start":
                    _engine.EnqueueCommand("start", null);
                    return ser.Serialize(new Dictionary<string, object> { { "ok", true }, { "queued", true } });
                case "stop":
                    _engine.EnqueueCommand("stop", null);
                    return ser.Serialize(new Dictionary<string, object> { { "ok", true }, { "queued", true } });
                case "adapters":
                    return ser.Serialize(new Dictionary<string, object>
                    {
                        { "ok", true },
                        { "adapters", _engine.ListAdaptersForUi() },
                        { "selected", AgentConfigStore.Load().WifiAdapterGuid ?? "" }
                    });
                case "set-adapter":
                    string guid = req != null && req.ContainsKey("guid") ? Convert.ToString(req["guid"]) : "";
                    _engine.SetWifiAdapterGuid(guid ?? "");
                    return ser.Serialize(new Dictionary<string, object> { { "ok", true }, { "guid", guid ?? "" } });
                default:
                    return ser.Serialize(new Dictionary<string, object> { { "ok", false }, { "error", "comando invalido" } });
            }
        }
        catch (Exception ex)
        {
            return new JavaScriptSerializer().Serialize(new Dictionary<string, object> { { "ok", false }, { "error", ex.Message } });
        }
    }
}

internal static class AgentPipeClient
{
    public static Dictionary<string, object> Send(string cmd)
    {
        try
        {
            using (var client = new System.IO.Pipes.NamedPipeClientStream(".", AgentConstants.PipeName, System.IO.Pipes.PipeDirection.InOut))
            {
                client.Connect(2000);
                using (var writer = new StreamWriter(client, Encoding.UTF8) { AutoFlush = true })
                using (var reader = new StreamReader(client, Encoding.UTF8))
                {
                    var ser = new JavaScriptSerializer();
                    writer.WriteLine(ser.Serialize(new Dictionary<string, object> { { "cmd", cmd } }));
                    string resp = reader.ReadLine();
                    return ser.DeserializeObject(resp) as Dictionary<string, object>;
                }
            }
        }
        catch
        {
            return null;
        }
    }
}

internal sealed class AgentLog
{
    private readonly Queue<Dictionary<string, object>> _entries = new Queue<Dictionary<string, object>>();
    private readonly object _gate = new object();

    public void Info(string msg)
    {
        Add("info", msg);
    }

    public void Warn(string msg)
    {
        Add("warn", msg);
    }

    public void Error(string msg)
    {
        Add("error", msg);
    }

    private void Add(string level, string msg)
    {
        lock (_gate)
        {
            var entry = new Dictionary<string, object>();
            entry["at"] = DateTime.Now.ToString("s");
            entry["level"] = level;
            entry["msg"] = msg;
            _entries.Enqueue(entry);
            while (_entries.Count > 40)
            {
                _entries.Dequeue();
            }
            WriteFile(entry);
        }
    }

    private static void WriteFile(Dictionary<string, object> entry)
    {
        try
        {
            string path = Path.Combine(AgentPaths.LogDir, "agent-" + DateTime.Now.ToString("yyyyMMdd") + ".log");
            File.AppendAllText(path, new JavaScriptSerializer().Serialize(entry) + Environment.NewLine, Encoding.UTF8);
        }
        catch
        {
        }
    }

    public object[] Recent()
    {
        lock (_gate)
        {
            return _entries.ToArray();
        }
    }
}

internal static class AtomicJson
{
    public static void Write(string path, object payload)
    {
        string dir = Path.GetDirectoryName(path);
        if (!string.IsNullOrEmpty(dir))
        {
            Directory.CreateDirectory(dir);
        }
        string json = new JavaScriptSerializer().Serialize(payload);
        string tmp = path + ".tmp";
        File.WriteAllText(tmp, json, new UTF8Encoding(false));
        if (File.Exists(path))
        {
            File.Replace(tmp, path, null);
        }
        else
        {
            File.Move(tmp, path);
        }
    }
}

internal static class AgentSecurity
{
    public static bool IsElevated()
    {
        try
        {
            var id = System.Security.Principal.WindowsIdentity.GetCurrent();
            var p = new System.Security.Principal.WindowsPrincipal(id);
            return p.IsInRole(System.Security.Principal.WindowsBuiltInRole.Administrator);
        }
        catch
        {
            return false;
        }
    }
}

internal static class ServiceInstallerHelper
{
    public static bool IsServiceInstalled()
    {
        return ServiceController.GetServices().Any(s => string.Equals(s.ServiceName, AgentConstants.ServiceName, StringComparison.OrdinalIgnoreCase));
    }

    public static void Install()
    {
        string exe = Process.GetCurrentProcess().MainModule.FileName;
        RunSc("create", AgentConstants.ServiceName, "binPath=", "\"" + exe + "\"", "start=", "auto", "DisplayName=", "\"Wi-Fi da Loja Agent\"");
        RunSc("description", AgentConstants.ServiceName, "\"Agente hotspot Wi-Fi da Loja\"");
        RunSc("failure", AgentConstants.ServiceName, "reset=", "86400", "actions=", "restart/60000/restart/60000/restart/60000");
        RunSc("start", AgentConstants.ServiceName);
    }

    public static void Uninstall()
    {
        RunSc("stop", AgentConstants.ServiceName);
        RunSc("delete", AgentConstants.ServiceName);
    }

    private static void RunSc(params string[] args)
    {
        var psi = new ProcessStartInfo
        {
            FileName = "sc.exe",
            Arguments = string.Join(" ", args),
            UseShellExecute = false,
            CreateNoWindow = true
        };
        var proc = Process.Start(psi);
        if (proc != null)
        {
            proc.WaitForExit(15000);
        }
    }
}

internal static class SessionWorker
{
    public static void Run()
    {
        AgentPaths.Init();
        AgentEngine.Instance.RunBlocking();
    }
}
